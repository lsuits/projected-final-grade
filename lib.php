<?php // $Id: lib.php,v 1.18.2.12 2007-11-01 11:45:04 skodak Exp $

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.com                                            //
//                                                                       //
// Copyright (C) 1999 onwards  Martin Dougiamas  http://moodle.com       //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 2 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////


// Projected Grade Report
// Adapted from User Report
// Edited by Adam Zapletal
// February 27, 2008

/**
 * File in which the user_report class is defined.
 * @package gradebook
 */

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir.'/tablelib.php');

/**
 * Class providing an API for the user report building and displaying.
 * @uses grade_report
 * @package gradebook
 */
class grade_report_projected extends grade_report {

    /**
     * The user.
     * @var object $user
     */
    var $user;

    /**
     * A flexitable to hold the data.
     * @var object $table
     */
    var $table;

    /**
     * Flat structure similar to grade tree
     */
    var $gseq;

    /**
     * Show hidden items even when user does not have required cap
     */
    var $showhiddenitems;

    // adamzap
    // init ajax_data
    private $ajax_data = array('items' => array(), 'categories' => array());

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $userid The id of the user
     */
    function __construct($courseid, $gpr, $context, $userid) {
        global $CFG, $DB;
        parent::__construct($courseid, $gpr, $context);

        $this->showhiddenitems = grade_get_setting($this->courseid, 'report_user_showhiddenitems', $CFG->grade_report_projected_showhiddenitems);

        $switch = grade_get_setting($this->courseid, 'aggregationposition', $CFG->grade_aggregationposition);

        // Grab the grade_seq for this course
        $this->gseq = new grade_seq($this->courseid, $switch);

        // get the user (for full name)
        $this->user = $DB->get_record('user', array('id' => $userid));

        // base url for sorting by first/last name
        $this->baseurl = $CFG->wwwroot.'/grade/report?id='.$courseid.'&amp;userid='.$userid;
        $this->pbarurl = $this->baseurl;

        // no groups on this report - rank is from all course users
        $this->setup_table();
    }

    /**
     * Prepares the headers and attributes of the flexitable.
     */
    function setup_table() {
        global $CFG;
        /*
         * Table has 5-6 columns
         *| itemname | category | final grade |
         */

        // adamzap
        // Edited table headers

        // setting up table headers
        $tablecolumns = array('itemname', 'category', 'grade');
        $tableheaders = array($this->get_lang_string('gradeitem', 'grades'), $this->get_lang_string('category'), $this->get_lang_string('grade'));

        $this->table = new flexible_table('grade-report-user-'.$this->courseid);

        $this->table->define_columns($tablecolumns);
        $this->table->define_headers($tableheaders);
        $this->table->define_baseurl($this->baseurl);

        $this->table->set_attribute('cellspacing', '0');
        $this->table->set_attribute('id', 'user-grade');
        $this->table->set_attribute('class', 'boxaligncenter generaltable');

        // not sure tables should be sortable or not, because if we allow it then sorted results distort grade category structure and sortorder
        $this->table->set_control_variables(array(
                TABLE_VAR_SORT    => 'ssort',
                TABLE_VAR_HIDE    => 'shide',
                TABLE_VAR_SHOW    => 'sshow',
                TABLE_VAR_IFIRST  => 'sifirst',
                TABLE_VAR_ILAST   => 'silast',
                TABLE_VAR_PAGE    => 'spage'
                ));

        $this->table->setup();
    }

    function fill_table() {
        global $CFG;

        $numusers = $this->get_numusers(false); // total course users
        $items =& $this->gseq->items;
        $grades = array();

        $canviewhidden = has_capability('moodle/grade:viewhidden', context_course::instance($this->courseid));

        // fetch or create all grades
        foreach ($items as $key=>$unused) {
            if (!$grade_grade = grade_grade::fetch(array('itemid'=>$items[$key]->id, 'userid'=>$this->user->id))) {
                $grade_grade = new grade_grade();
                $grade_grade->userid = $this->user->id;
                $grade_grade->itemid = $items[$key]->id;
            }
            $grades[$key] = $grade_grade;
            $grades[$key]->grade_item =& $items[$key];
        }

        $file = $CFG->dirroot. '/grade/report/simple_grader/lib/simple_gradelib.php';
        if (file_exists($file)) {
            require_once($file);
            require_once($CFG->dirroot. '/grade/report/simple_grader/lib/simple_grade_hook.php');
        }

        $altered = array();
        $unknown = array();

        foreach ($items as $itemid=>$unused) {
            $grade_item  =& $items[$itemid];
            $grade_grade =& $grades[$itemid];

            // adamzap
            // Fill course total data
            if ($grade_item->itemtype == 'course') {
                $ct_data = new stdClass;
                $ct_data->calculation = $grade_item->calculation;
                $ct_data->aggregationcoef = $grade_item->aggregationcoef;
                $ct_data->grademin = $grade_item->grademin;
                $ct_data->grademax = $grade_item->grademax;
                // in case of aggregation, we'll need these values later
                $ct_grademin = $grade_item->grademin;
                $ct_grademax = $grade_item->grademax;

                // new fields required by grade_format_gradevalue 03/11/08
                $ct_data->gradetype = $grade_item->gradetype;
                $ct_data->display = $grade_item->get_displaytype();
                $ct_data->decimals = $grade_item->get_decimals();
                $ct_data->scaleid = $grade_item->scaleid;
                $ct_data->courseid = $grade_item->courseid;

                $this->ajax_data['course_total'] = $ct_data;
            }

            // adamzap
            // this logic was taken out because we are handling the hidden grades differently
            // we only want to completely overlook a grade if it is not a category because
            // category totals are needed to compute the final grade on the fly
            //
            // Hidden checks were moved from here to right before rendering

            $class = 'gradeitem';
            if ($grade_item->is_course_item()) {
                $class = 'courseitem';
            } else if ($grade_item->is_category_item()) {
                $class = 'categoryitem';

                // make category totals invisible to students
                if (!$this->showhiddenitems) {
                    if (!$canviewhidden && $grade_item->is_hidden()) {
                        $class .= ' invisitext';
                    }
                } else if ($this->showhiddenitems == 1 && $grade_item->is_hiddenuntil()) {
                    $class .= ' invisitext';
                }
            }

            if (!empty($unknown) and in_array($itemid, $unknown)) {
                $gradeval = null;
            } else if (!empty($altered) and array_key_exists($itemid, $altered)) {
                $gradeval = $altered[$itemid];
            } else {
                $gradeval = $grade_grade->finalgrade;
            }

            $data = array();

            // all users should know which items are still hidden
            $hidden = '';
            if ($grade_item->is_hidden()) {
                $hidden = ' hidden ';
            }

            $element = $this->gseq->locate_element($this->gseq->get_item_eid($grade_item));
            $header = $this->gseq->get_element_header($element, true, true, true);

            /// prints grade item name
            $data[] = '<span class="'.$hidden.$class.'">'.$header.'</span>';

            /// prints category
            $cat = $grade_item->get_parent_category();
            $data[] = '<span class="'.$hidden.$class.'">'.$cat->get_name().'</span>';

            // adamzap

            // make sure that the ajax data has a key for this category
            // also, make sure this category isn't the overall course category
            // if not, create one and initialize arrays
            if(!array_key_exists($cat->id, $this->ajax_data['categories']) && $cat->parent != '') {
                $cat_data = new stdClass;
                $cat_data->aggregation = $cat->aggregation;
                $cat_data->aggregatesubcats = $cat->aggregatesubcats;
                $cat_data->droplow = $cat->droplow;
                $cat_data->keephigh = $cat->keephigh;
                $cat_data->path = $cat->path;
                $cat_data->grademax = $cat->get_grade_item()->grademax;

                $this->ajax_data['categories'][$cat->id] = $cat_data;
            }

            // insert grade and item data into the ajax data array
            if ($grade_item->itemtype != 'course') {
                $item_data = new stdClass;

                if ($grade_item->calculation) {
                    $item_data->calculation = $grade_item->calculation;
                    $item_data->calculated = false;
                }

                $item_data->itemtype = $grade_item->itemtype;
                $item_data->aggregationcoef = $grade_item->aggregationcoef;
                $item_data->grademax = $grade_item->grademax;
                $item_data->grademin = $grade_item->grademin;

                // new fields required by grade_format_gradevalue 03/11/08
                $item_data->gradetype = $grade_item->gradetype;
                $item_data->display = $grade_item->get_displaytype();
                $item_data->decimals = $grade_item->get_decimals();
                $item_data->scaleid = $grade_item->scaleid;
                $item_data->courseid = $grade_item->courseid;

                // new fields 12/22/08
                $item_data->plusfactor = $grade_item->plusfactor;
                $item_data->multfactor = $grade_item->multfactor;

                $item_data->excluded = $grade_grade->excluded;

                if ($grade_item->itemtype == 'category') {
                    $item_data->categoryid = $grade_item->iteminstance;
                    $item_data->parent = $cat->parent;
                    $this->ajax_data['categories'][$grade_item->iteminstance]->calculation = $grade_item->calculation;
                } else {
                    $item_data->categoryid = $grade_item->categoryid; 
                    $item_data->parent = null;
                }

                $item_data->value = $grade_grade->finalgrade;

                $this->ajax_data['items'][$grade_item->id] = $item_data;
            }

            $hidden = '';
            if ($grade_item->is_hidden()) {
                // can not see grades in hidden items
                $hidden = ' hidden ';
            } else if ($canviewhidden and $grade_grade->is_hidden()) {
                // if user can see hidden grades, indicate which ones are hidden
                $hidden = ' hidden ';
            }

            /// prints the grade
            if ($grade_grade->is_excluded()) {
                $excluded = get_string('excluded', 'grades').' ';
            } else {
                $excluded = '';
            }

            if ($grade_item->needsupdate) {
                $data[] = '<span class="'.$hidden.$class.' gradingerror">'.get_string('error').'</span>';

            } else if (!empty($CFG->grade_hiddenasdate) and $grade_grade->get_datesubmitted() and !$canviewhidden and $grade_grade->is_hidden()
                   and !$grade_item->is_category_item() and !$grade_item->is_course_item()) {
                // the problem here is that we do not have the time when grade value was modified, 'timemodified' is general modification date for grade_grades records
                $data[] = '<span class="'.$hidden.$class.' datesubmitted">'.$excluded.get_string('submittedon', 'grades', userdate($grade_grade->get_datesubmitted(), get_string('strftimedatetimeshort'))).'</span>';

            } else {
                if (is_null($gradeval)) {
                // adamzap
                // Fill course total data for aggregation
                    if ($grade_item->itemtype == 'course') {
                        if (!$this->ajax_data['course_total']->calculation) {
                            $ct_cat = $grade_item->get_parent_category();
                            $ct_data = new stdClass;
                            $ct_data->id = $ct_cat->id;
                            $ct_data->aggregation = $ct_cat->aggregation;
                            $ct_data->aggregatesubcats = $ct_cat->aggregatesubcats;
                            $ct_data->droplow = $ct_cat->droplow;
                            $ct_data->keephigh = $ct_cat->keephigh;
                            $ct_data->path = $ct_cat->path;
                            $ct_data->grademin = $this->ajax_data['course_total']->grademin;
                            $ct_data->grademax = $this->ajax_data['course_total']->grademax;

                            // new fields required by grade_format_gradevalue 03/11/08
                            $ct_data->gradetype = $this->ajax_data['course_total']->gradetype;
                            $ct_data->display = $grade_item->get_displaytype();
                            $ct_data->decimals = $grade_item->get_decimals();
                            $ct_data->scaleid = $this->ajax_data['course_total']->scaleid;
                            $ct_data->courseid = $ct_cat->courseid;

                            $this->ajax_data['course_total'] = $ct_data;
                        }

                        $data[] = '<span id = "calc_total_grade" class="'.$hidden.$class.'"><b>'.$excluded. '0.00</b></span>';
                    } else if ($grade_item->itemtype == 'category' || $grade_item->calculation) {
                        $data[] = '<span id = "calc_item_grade_' . $itemid . '" class="'.$hidden.$class.'"><b>'.$excluded . '0.00</b></span>';
                    } else {
                        $data[] = '<span class="'.$hidden.$class.'"><input type = "text" size = "4" id = "calc_grade_' . $itemid . 
                            '" /><input type = "hidden" id = "minmax_' . $itemid . '" value = "' . $grade_item->grademin . '|' . $grade_item->grademax . '"/>' .
                            '<span id="' . $itemid . '_range"> ' . get_string('out_of', 'gradereport_projected') . ' ' . round($grade_item->grademax) . '</span></span>';
                    }
                } else {
                    if ($grade_item->itemtype == 'course') {
                        if (!$this->ajax_data['course_total']->calculation) {
                            $ct_cat = $grade_item->get_parent_category();
                            $ct_data = new stdClass;
                            $ct_data->id = $ct_cat->id;
                            $ct_data->aggregation = $ct_cat->aggregation;
                            $ct_data->aggregatesubcats = $ct_cat->aggregatesubcats;
                            $ct_data->droplow = $ct_cat->droplow;
                            $ct_data->keephigh = $ct_cat->keephigh;
                            $ct_data->path = $ct_cat->path;
                            $ct_data->grademin = $this->ajax_data['course_total']->grademin;
                            $ct_data->grademax = $this->ajax_data['course_total']->grademax;

                            $ct_data->gradetype = $this->ajax_data['course_total']->gradetype;
                            $ct_data->display = $grade_item->get_displaytype();
                            $ct_data->decimals = $grade_item->get_decimals();
                            $ct_data->scaleid = $this->ajax_data['course_total']->scaleid;
                            $ct_data->courseid = $ct_cat->courseid;

                            $this->ajax_data['course_total'] = $ct_data;
                        }

                        $gradedisplay = grade_format_gradevalue($gradeval, $grade_item);

                        $data[] = '<span id = "calc_total_grade" class="'.$hidden.$class.'"><b>'.$excluded.$gradedisplay . '</b></span>';
                    } else if($grade_item->itemtype == 'category') {
                        $data[] = '<span id = "calc_item_grade_' . $itemid . '" class="'.$hidden.$class.'"><b>'.$excluded.grade_format_gradevalue($gradeval, $grade_item, true) . '</b></span>';
                    } else {
                        if ($grade_item->calculation) {
                            $data[] = '<span id = "calc_item_grade_' . $itemid . '" class="'.$hidden.$class.'"><b>'.$excluded.grade_format_gradevalue($gradeval, $grade_item, true) . '</b></span>';
                        } else {
                            $data[] = '<span id = "calc_item_grade_' . $itemid . '" class="'.$hidden.$class.'">'.$excluded.grade_format_gradevalue($gradeval, $grade_item, true) . '</span>';
                        }
                    }
                }
            }

            // Check if user can see hidden after data is prepared to be passed.
            // Hidden data is only excluded in rendering.
            if (!$grade_item->is_category_item()) {
                if (!$canviewhidden and $grade_item->is_hidden()) {
                    if ($this->showhiddenitems == 0) {
                        // no hidden items at all
                        continue;
                    } else if ($this->showhiddenitems == 1 and !$grade_item->is_hiddenuntil()) {
                        // hidden until that are still hidden are visible
                        continue;
                    }
                }
            }

            $this->table->add_data($data);
        }

        $context = context_course::instance($grade_item->courseid);
        $the_letters = grade_get_letters($context);
        array_pop($the_letters);
        $this->ajax_data['letters'] = $the_letters;

        // adamzap
        // Sort ajax_data and add it to $SESSION
        ksort($this->ajax_data['items']);
        ksort($this->ajax_data['categories']);
        global $SESSION;
        $SESSION->projected_ajax_data = $this->ajax_data;

        return true;
    }

    /**
     * Prints or returns the HTML from the flexitable.
     * @param bool $return Whether or not to return the data instead of printing it directly.
     * @return string
     */
    function print_table($return=false) {
        ob_start();
        $this->table->print_html();
        $html = ob_get_clean();

        if ($return) {
            return $html;
        } else {
            echo $html;
        }
    }

    /**
     * Processes the data sent by the form (grades and feedbacks).
     * @var array $data
     * @return bool Success or Failure (array of errors).
     */
    function process_data($data) {
    }

    function process_action($target, $action) {
    }
}

function grade_report_projected_settings_definition(&$mform) {
    global $CFG;

    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('hide'),
                      1 => get_string('showhiddenuntilonly', 'grades'),
                      2 => get_string('show'));

    if (empty($CFG->grade_report_projected_showhiddenitems)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $mform->addElement('select', 'report_projected_showhiddenitems', get_string('showhiddenitems', 'grades'), $options);
    $mform->addHelpButton('report_projected_showhiddenitems', 'showhiddenitems', 'grades');

    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('no'),
                      1 => get_string('yes'));

    if (empty($CFG->grade_report_projected_enabled_for_students)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $mform->addElement('select', 'report_projected_enabled_for_students', 
                        get_string('enabled_for_students', 'gradereport_projected'), $options);

    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('no'),
                      1 => get_string('yes'));

    if (empty($CFG->grade_report_projected_must_make_enabled)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $mform->addElement('select', 'report_projected_must_make_enabled',
                        get_string('must_make_enabled', 'gradereport_projected'), $options);
}

function grade_report_projected_profilereport($course, $user) {
    if (!empty($course->showgrades)) {

        $context = context_course::instance($course->id);

        //first make sure we have proper final grades - this must be done before constructing of the grade tree
        grade_regrade_final_grades($course->id);

        /// return tracking object
        $gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'user', 'courseid'=>$course->id, 'userid'=>$user->id));
        // Create a report instance
        $report = new grade_report_projected($course->id, $gpr, $context, $user->id);

        // print the page
        echo '<div class="grade-report-user">'; // css fix to share styles with real report page
        print_heading(get_string('modulename', 'gradereport_user'). ' - '.fullname($report->user));

        if ($report->fill_table()) {
            echo $report->print_table(true);
        }
        echo '</div>';
    }
}

// Added to create dropdown list of users for instructors to switch to
function print_projected_graded_users_selector($course, $actionpage, $userid=null) {
    global $CFG, $OUTPUT, $USER;

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    $context = context_course::instance($course->id);

    $menu = array(); // Will be a list of userid => user name

    $gui = new graded_users_iterator($course);
    $gui->init();

    while ($userdata = $gui->next_user()) {
        $user = $userdata->user;
        $menu[$user->id] = fullname($user);
    }

    $gui->close();

    $params = array('id' => $course->id);
    $url = new moodle_url('/grade/report/projected/index.php', $params);

    echo $OUTPUT->single_select($url, 'userid', $menu);
}

?>
