<?php // $Id: settings.php,v 1.1.2.4 2007-10-30 21:41:42 skodak Exp $

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.com                                            //
//                                                                       //
// Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com     //
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

/// Add settings for this module to the $settings object (it's already defined)

$_s = function($key) { return get_string($key, 'gradereport_projected'); };

$options = array(
    0 => $_s('show_no_hidden'),
    1 => $_s('show_hidden_until_only'),
    2 => $_s('show_all_hidden')
);

$settings->add(new admin_setting_configselect('grade_report_projected_showhiddenitems', $_s('show_hidden_items'), $_s('show_hidden_items_desc'), 1, $options));

$settings->add(new admin_setting_configcheckbox('grade_report_projected_enabled_for_students', $_s('enabled_for_students'), $_s('enabled_for_students_desc'), 1));

$settings->add(new admin_setting_configcheckbox('grade_report_projected_must_make_enabled', $_s('must_make_enabled'), $_s('must_make_enabled_desc'), 1));

?>
