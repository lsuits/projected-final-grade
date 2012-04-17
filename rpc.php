<?php

/*
 * @author Adam Zapletal
 * February 27, 2008
 */

  ////////////////
 //  REQUIRES  //
////////////////

require_once('../../../config.php');
require_once($CFG->libdir . '/mathslib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/grade/constants.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->libdir . '/grade/grade_category.php');

INI_SET('max_execution_time', '600');

  //////////////////////////
 //  INTERNAL FUNCTIONS  //
//////////////////////////

// Takes an array of id=value pairs and splits and organizes them
// into an array of itemid => rounded_value pairs
// Particularly, $inputs will be the ids and values of the dynamically 
// generated textboxes from the index page passed in through the ajax call
function read_new_grades($inputs) {
    foreach ($inputs as $input) {
        // Skip processing input item unless it has an element id and
        // a numeric value
        list($elementid, $value) = explode('=', $input);

        if (!($elementid && (is_numeric($value)) || $value == 'switch_me')) {
            continue;
        }

        // Skip processing input item if element is not a grade element
        list($trash, $name, $id) = explode('_', $elementid);

        if ($name != 'grade') {
            continue;
        }

        $new_grades[$id] = round($value, 5);
    }

   return $new_grades;
}

// Filter function for array_filter
// Checks if a category or item has a calculation
function has_calculation($obj) {
    return isset($obj->calculation) and $obj->calculation;
}

// Filter function for array_filter
// Expects an item, returns only category totals
function is_category_total($obj) {
    return $obj->itemtype == 'category';
}

// Filter function for array_filter
// Expects an item, returns only manual items
function is_manual($obj) {
    return $obj->itemtype == 'manual';
}

// If a course has multiple calculated items, one may depend on the
// calculation of another. Therefore, we have to decide which calculation(s)
// to run first so the later ones can be accurate.
// Expects the course's items array, and an array of ordered item ids. The
// second array will be built by this function's recursive calls to itself.
function build_dependency_order($items, $ordered=null) {
    if (!$ordered) {
        $ordered = array();
    }

    $calc_items = array_filter($items, 'has_calculation');

    foreach ($calc_items as $id => $item) {
        preg_match_all('/##gi(\d+)##/', $item->calculation, $matches);
        $depends_on = array_unique($matches[1]);

        $inner_array = array();

        foreach ($depends_on as $gi_id) {
            $inner_array[$gi_id] = $items[$gi_id];
        }

        $ordered = build_dependency_order($inner_array, $ordered);

        $ordered[] = $id;

        foreach ($depends_on as $gi_id) {
            if ($items[$gi_id]->calculation) {
                $ordered[] = $gi_id;
            }
        }
    }

    return $ordered;
}

// Recursively checks that each prerequisite for this item's calculation
// has already been calculated
function prepare_for_calculation($itemid, $items) {
    $params = array();
    preg_match_all('/##(gi\d+)##/', $items[$itemid]->calculation, $matches);
    $depends_on = array_unique($matches[1]);

    foreach ($depends_on as $gi_id) {
        $itemid = substr($gi_id, 2);

        if ($items[$itemid]->calculation && !$items[$itemid]->calculated) {
            $tmp_params = prepare_for_calculation($itemid, $items);
            $items[$itemid]->value = use_formula($items[$itemid]->calculation,
                $tmp_param);

        } else {
            $params[$gi_id] = $items[$itemid]->value;
        }
    }

    return $params;
}

// Takes the category id of the category that needs to be aggregated, the
// course's categories array, the courses items array, and a flag indicating
// whether or not this is the course total category.
// Returns an array of the two arrays that are prepared to be passed to 
// aggregate_category.
function prepare_for_aggregation($catid, $categories, $items, $course_total) {
    $required_items = array();
    $cat_items = array();
    $grade_values = array();

    if ($categories[$catid]->aggregatesubcats) {
        // If we are aggregating subcategories, we need all grade items from this
        // category down.
        $manual_items = array_filter($items, 'is_manual');

        foreach ($manual_items as $id => $item) {
            if (!(strpos($categories[$item->categoryid]->path, $categories[$catid]->path) === false)) {
                $required_items[] = $id;
            }
        }
    } else {
        // If we are not aggregating subcategories, we only need this category's
        // immediate items and the category totals inside it.
        foreach ($items as $id => $item) {
            if (($item->categoryid == $catid && $item->itemtype != 'category') || ($course_total && $item->parent == $catid)) {
                // An immediate item has been found.
                $required_items[] = $id;
            } else if (!(strpos($categories[$item->categoryid]->path, $categories[$catid]->path) === false)) {
                if ($item->itemtype == 'category' && $item->categoryid != $catid) {
                    $path_parts = explode('/', $categories[$item->categoryid]->path);
                    $pos = array_search($catid, $path_parts);

                    if ($path_parts[$pos + 1] == $item->categoryid) {
                        // A nested category total has been found.
                        $required_items[] = $id;
                    }
                }
            }
        }
    }

    // Prepare the grade_values and cat_items arrays from required_items
    if ($categories[$catid]->aggregation == GRADE_AGGREGATE_SUM) {
        foreach ($required_items as $id) {
            $grade_values[$id] = $items[$id]->value;
            $cat_items[$id] = $items[$id];
        }
    } else {
        foreach ($required_items as $id) {
            // Hardcoded grademin to 0 to fix scale (and maybe assignment) grades
            $grade_values[$id] = grade_grade::standardise_score($items[$id]->value,
                0, $items[$id]->grademax, 0, 1);
            $cat_items[$id] = $items[$id];
        }
    }

    return array($grade_values, $cat_items);
}

// Calculates a grade using a formula with a given calculation
// This is a hacked-together verison of grade_item->use_formula
function use_formula($calculation, $params) {
    $formula = new calc_formula(preg_replace('/##(gi\d+)##/', '\1', $calculation));
    $formula->set_params($params);

    return $formula->evaluate();
}

// Builds the paramaters array that will be passed to use_formula when
// calculating an item. This is abstracted out of compute_items because it has
// to be called explicitly when computing the course total
function build_params($items, $calculation) {
    $params = array();
    preg_match_all('/##(gi\d+)##/', $calculation, $matches);

    foreach(array_unique($matches[1]) as $gi_id) {
        $params[$gi_id] = $items[substr($gi_id, 2)]->value;
    }

    return $params;
}


// Calculates each item, if necessary, and returns the items array with updated
// values for those that were calculated.
function compute_items($items, $calc_order) {
    foreach ($calc_order as $id) {
        $params = build_params($items, $items[$id]->calculation);
        $items[$id]->value = use_formula($items[$id]->calculation, $params);
        $items[$id]->calculated = true;
    }

    return $items;
}

// Takes the course's array of categories and returns an array of category ids
// that are in the order in which their respective categories should be
// aggregated. A category may depend on the aggregation/category total of
// another, so this must be done.
function order_categories($categories) {
    $order = array();

    ksort($categories);

    foreach ($categories as $id => $category) {
        foreach (explode('/', $category->path) as $id) {
            if (!in_array($id, $order) && array_key_exists($id, $categories)) {
                $order[] = $id;
            }
        }
    }

    return array_reverse(array_filter($order));
}

// When aggregating categories, we do not have access to the id of item which
// represents each category's total, given our data structure. This function
// builds a lookup associating each category_id to the id of the corresponding
// category total item.
function build_category_lookup($items) {
    $lookup = array();

    // Get an array of all category total items
    $cat_totals = array_filter($items, 'is_category_total');

    foreach ($cat_totals as $itemid => $itemdata) {
        $lookup[$itemdata->categoryid] = $itemid;
    }

    return $lookup;
}

// Abstracts the aggregation of a single category. Takes the course's items and
// categories array, the id of the category that needs to be aggregated, and a
// flag that indicates whether or not the course total is being passed it.
// The course total is a special case because the items on which it depends
// have a NULL categoryid.
function aggregate_category($items, $catid, $categories, $course_total=false) {
    $agg_data = prepare_for_aggregation($catid, $categories, $items, $course_total);
    $grade_values = $agg_data[0];
    $cat_items = $agg_data[1];

    $cat_obj = new grade_category(array('aggregation' => $categories[$catid]->aggregation,
       'droplow' => $categories[$catid]->droplow, 'keephigh' => $categories[$catid]->keephigh), false);

    // Apply limit rules (droplow, keephigh) and sort
    $cat_obj->apply_limit_rules($grade_values, $items);
    asort($grade_values, SORT_NUMERIC);

    foreach ($cat_items as $k => $cat_item) {
        if ($cat_item->excluded) {
            unset($cat_items[$k]);
        }
    }

    if ($cat_obj->aggregation == GRADE_AGGREGATE_SUM) {
        $agg_grade = projected_sum_grades($catid, $cat_obj, $grade_values, $cat_items);
    } else {
        $cat_obj->grade_item->grademax = $categories[$catid]->grademax;
        $agg_grade = $cat_obj->aggregate_values($grade_values, $cat_items);
    }

    return $agg_grade;
}

// Takes the course's items array and the course's categories array.
// Calls aggregate_category on each category and fills the items array
// with the computed values. Then, it returns the array.
function compute_categories($items, $categories) {
    if (!$lookup = build_category_lookup($items)) {
        return $items;
    }

    $order = order_categories($categories);

    foreach ($order as $catid) {
        $agg_grade = aggregate_category($items, $catid, $categories);

        if ($categories[$catid]->aggregation == GRADE_AGGREGATE_SUM) {
            $finalgrade = $agg_grade;
        } else {
            $finalgrade = grade_grade::standardise_score($agg_grade,
                0, 1, $items[$lookup[$catid]]->grademin, $items[$lookup[$catid]]->grademax);
        }

        // Do additional work for category item grade offset and curve to
        $plusfactor = $items[$lookup[$catid]]->plusfactor;
        $multfactor = $items[$lookup[$catid]]->multfactor;

        $items[$lookup[$catid]]->value = ($finalgrade * $multfactor) + $plusfactor;
        $items[$lookup[$catid]]->aggregated = true;
    }

    return $items;
}

// All checks for GRADE_AGGREGATE_SUM are new as well
// This function is a tweaked copy of grade_category's sum_graded method. It 
// must be pulled out and tweaked so we don't save anything to the database.
function projected_sum_grades($catid, $cat_obj, $grade_values, $items) {
    // ungraded and exluded items are not used in aggregation
    foreach ($grade_values as $itemid=>$v) {
        if (is_null($v)) {
            unset($grade_values[$itemid]);
        }
    }

    // use 0 if grade missing, droplow used and aggregating all items
    if (!$cat_obj->aggregateonlygraded and !empty($cat_obj->droplow)) {
        foreach($items as $itemid=>$value) {
            // if (!isset($grade_values[$itemid]) and !in_array($itemid, $excluded)) {
            if (!isset($grade_values[$itemid])) {
                $grade_values[$itemid] = 0;
            }
        }
    }

    $max = 0;

    //find max grade
    foreach ($items as $item) {
        if ($item->aggregationcoef > 0) {
            // extra credit from this activity - does not affect total
            continue;
        }

        if ($item->gradetype == GRADE_TYPE_VALUE) {
            $max += $item->grademax;
        } else if ($item->gradetype == GRADE_TYPE_SCALE) {
            $max += $item->grademax - 1; // scales min is 1
        }
    }

    $cat_obj->apply_limit_rules($grade_values);

    $sum = array_sum($grade_values);

    $finalgrade = bounded_number(0, $sum, $max);

    return $finalgrade;

}

// Writes a response string to be handled by javascript when the request
// returns. Gleans data from the $items array for the textboxes whose values 
// need to be updated on the page.
function prepare_response_string($items) {
    $out = '';
    foreach ($items as $id => $item) {
        $calculated = isset($item->calculated) and $item->calculated;
        $aggregated = isset($item->aggregated) and $item->aggregated;

        if ($calculated or $aggregated) {
            if (!$out == '') {
                $out .= '|';
            }

            $formatted = grade_format_gradevalue($item->value, $item, true, $item->display, $item->decimals);

            $out .= 'calc_item_grade_' . $id . '=<b>' . $formatted . '</b>'; 
        }
    }

    return $out ? $out . '|' : $out;
}

function calculate_course_total($course_total, $items, &$categories) {
    if (isset($course_total->calculation) and $course_total->calculation) {
        // Course Total is calculated
        $ct_params = build_params($items, $course_total->calculation);
        $ct_value = number_format(use_formula($course_total->calculation, $ct_params), 2);
    } else {
        // Course Total is aggregated
        $categories[$course_total->id] = $course_total;
        $agg_grade = aggregate_category($items, $course_total->id, $categories, true);

        if ($course_total->aggregation != GRADE_AGGREGATE_SUM) {
            $ct_value = grade_grade::standardise_score($agg_grade, 0, 1,
                $course_total->grademin, $course_total->grademax);
        } else {
            $ct_value = $agg_grade;
        }

        $ct_value = number_format($ct_value, 2);
    }

    return $ct_value;
}

function calculate_must_make($item_id, $letters, $course_total, $items, $categories) {
    $limits = array();
    $cache = array();

    $last_bound = null;

    foreach ($letters as $bound => $letter) {
        $min = $items[$item_id]->grademin;
        $max = $items[$item_id]->grademax;
        $bound = round($bound, 2);

        $ct_value = null;

        $found = false;

        // Binary search
        while ($min <= $max) {
            $mid = round(($min + $max) / 2);

            if (!empty($cache[$mid])) {
                $ct_value = $cache[$mid];
            } else {
                $items[$item_id]->value = $mid;

                $items = compute_items($items, array_unique(build_dependency_order($items)));
                $items = compute_categories($items, array_diff_key($categories, array_filter($categories, 'has_calculation')));

                $ct_value = calculate_course_total($course_total, $items, $categories);

                $cache[$mid] = $ct_value;
            }

            if ($ct_value == $bound) {
                $found = true;
                break;
            } else if ($ct_value > $bound) {
                $max = $mid - 1;
            } else {
                $min = $mid + 1;
            }
        }

        // Accept values higher than highest bound and ones between bounds
        if ($ct_value > $bound && ($last_bound == null || ($last_bound && $ct_value < $last_bound))) {
            $found = true;
        }

        $in_range = $mid + 1 >= $items[$item_id]->grademin && $mid + 1 <= $items[$item_id]->grademax;

        if ($ct_value < $bound && $in_range) {
            $mid += 1;
            $found = true;
        }

        $limits[] = $found ? $mid : '&times;';

        $last_bound = $bound;
    }


    $out = '';

    $letters_only = array_values($letters);

    foreach (range(0, count($letters_only) - 1) as $n) {
        $out .= $letters_only[$n] . ',' . $limits[$n] . ',';
    }

    return rtrim($out, ',');
}

  ////////////
 //  MAIN  //
////////////

// User must be logged in
// We don't want arbitrary requests being made to this page
require_login();

// Read the post data and mold it into something useful
$inputs = explode('|', required_param('inputdata', PARAM_RAW));

// Check for must_make
$must_make_item_id = null;

if (substr($inputs[count($inputs) - 1], 0, 9) == 'must_make') {
    $must_make_item_id = end(explode('=', array_pop($inputs)));
}

// Rebuild the course information data structure then split it into meaningful parts
$course_data = $SESSION->projected_ajax_data;
$items = $course_data['items'];
$categories = $course_data['categories'];
$course_total = $course_data['course_total'];
$letters = $course_data['letters'];
unset($course_data);

// Add the new grades from textboxes to the items array
$new_grades = read_new_grades($inputs);
foreach ($new_grades as $id => $value) {
    // If value is 'switch_me', then we need to input the grade item's minimum value
    // This could not be done earlier because we did not have that data.
    if ($value == 'switch_me') {
        $value = $items[$id]->grademin;
    }

    $items[$id]->value = number_format($value, 5);

    // Fix grades for curve to (multfactor) and offset (plusfactor)
    $items[$id]->value = $items[$id]->value * $items[$id]->multfactor + $items[$id]->plusfactor;
}

// Calculate all items with a calculation by passing the course's items array
// and a unique, ordered array of item_ids to compute_items
$items = compute_items($items, array_unique(build_dependency_order($items)));

// Update the items array with computed category values.
// A category can have a calculated total, so at this point it would already
// be calculated. Therefore, the second argument must be the $categories array
// with any calculated totals filtered out.
$items = compute_categories($items, array_diff_key($categories,
    array_filter($categories, 'has_calculation')));

$ct_value = calculate_course_total($course_total, $items, $categories);

$ct_value = grade_format_gradevalue($ct_value, $course_total, true, $course_total->display, $course_total->decimals);

echo prepare_response_string($items) . 'calc_total_grade=<b>' . $ct_value . '</b>';

if ($must_make_item_id) {
    echo '|must_make=' . calculate_must_make($must_make_item_id, $letters, $course_total, $items, $categories);
}

?>
