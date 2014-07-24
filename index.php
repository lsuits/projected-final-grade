<?php // $Id: index.php,v 1.26.2.1 2007-10-27 20:36:34 skodak Exp $

///////////////////////////////////////////////////////////////////////////
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
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

require_once '../../../config.php';
require_once $CFG->libdir.'/gradelib.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/projected/lib.php';

$_s = function($key) { return get_string($key, 'gradereport_projected'); };

$PAGE->requires->js('/grade/report/projected/js/jquery.js');
$PAGE->requires->js('/grade/report/projected/js/projected.js');

$courseid = required_param('id', PARAM_INT);
$userid   = optional_param('userid', $USER->id, PARAM_INT);

$params = array('id'=>$courseid);
$PAGE->set_url(new moodle_url('/grade/report/projected/index.php', $params));

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('nocourseid');
}

require_login($course);

$PAGE->set_pagelayout('report');

if (!$user = get_complete_user_data('id', $userid)) {
    print_error('Incorrect userid');
}

$context = context_course::instance($course->id);

require_capability('gradereport/projected:view', $context);

if (empty($userid)) {
    require_capability('moodle/grade:viewall', $context);

} else {
    if (!$DB->get_record('user', array('id'=>$userid, 'deleted'=>0)) or isguestuser($userid)) {
        print_error('invaliduser');
    }
}

$access = false;

$view_all = has_capability('moodle/grade:viewall', $context);
$view_own = $userid == $USER->id and has_capability('moodle/grade:view', $context) and $course->showgrades;
$view_user = has_capability('moodle/grade:viewall', context_user::instance($userid)) and $course->showgrades;

if (!($view_all or $view_own or $view_user)) {
    print_error('nopermissiontoviewgrades', 'error',  $CFG->wwwroot.'/course/view.php?id=' . $courseid);

    echo $OUTPUT->footer();
    die();
}

$gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'projected', 'courseid'=>$courseid, 'userid'=>$userid));

if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}

$USER->grade_last_report[$course->id] = 'projected';

$report = new grade_report_projected($courseid, $gpr, $context, $userid);

print_grade_page_head($course->id, 'report', 'projected', $_s('pluginname'). ' - '.fullname($report->user));

$is_enabled = grade_get_setting($courseid, 'report_projected_enabled_for_students', $CFG->grade_report_projected_enabled_for_students);

if (!$is_enabled and !$view_all) {
    echo '<div class = "enabled_projected_error">'.get_string('your_teacher', 'gradereport_projected').'</div>';

    echo $OUTPUT->footer();
    die();
}

grade_regrade_final_grades($courseid);

if ($view_all) {
    // Added to allow for simple selection of a user form the course.
    // Print graded user selector at the top
    echo '<div id="graded_users_selector">';
    print_projected_graded_users_selector($course, 'report/projected/index.php?id=' . $course->id, $userid);
    echo '</div>';
    echo "<p style = 'page-break-after: always;'></p>";
}

if ($report->fill_table()) {
    echo $report->print_table(true);
}

$params = array(
    'width' => '20%',
    'class' => 'generaltable must_make_table',
    'cellpadding' => '0',
    'cellspacing' => '0',
);

$must_make_table = new html_table();
$must_make_table->attributes = $params;
$must_make_table->head = array('Must Make');
$must_make_table->align= array('center');
$must_make_table->data = array();

$letters = grade_get_letters($context);

array_pop($letters);

foreach ($letters as $letter) {
    $must_make_table->data[] = new html_table_row(array(
        new html_table_cell(' ')
    ));
}

$must_make_enabled = grade_get_setting($course->id, 'report_projected_must_make_enabled', !empty($CFG->grade_report_projected_must_make_enabled));

echo '<input type="hidden" id="must_make_enabled" value="' . $must_make_enabled . '"/>';

if ($must_make_enabled) {
    echo '<div id = "must_make">';
    echo html_writer::table($must_make_table);
    echo '</div>';
}

echo $OUTPUT->footer();

?>
