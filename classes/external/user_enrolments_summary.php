<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace enrol_select\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use enrol_select_plugin;
use moodle_url;
use stdClass;
use context_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/externallib.php');

/**
 * Webservice afficher les inscriptions d'un utilisateur.
 *
 * @package   enrol_select
 * @copyright 2026 Université Rennes 2
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_enrolments_summary extends external_api {
    /**
     * Renvoie la description des paramètres de la méthode.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Identifiant de l\'utilisateur'),
            'courseid' => new external_value(PARAM_INT, 'Identifiant du cours actuellement consulté'),
        ]);
    }

    /**
     * Cherche les informations concernant toutes les inscriptions de l'utilisateur, et les éventuels paiements en attente.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass $userenrolments récapitulatif des inscriptions de l'utilisateur.
     */
    public static function execute(int $userid, int $courseid): stdClass {
        global $CFG;
        global $DB;

        require_once($CFG->dirroot . '/enrol/select/lib.php');
        require_once($CFG->dirroot . '/enrol/select/locallib.php');

        ['userid' => $userid, 'courseid' => $courseid] = self::validate_parameters(
            self::execute_parameters(),
            ['userid' => $userid, 'courseid' => $courseid]
        );

        // Types d'inscription.
        $roles = role_fix_names($DB->get_records('role'));

        // Types de calendrier.
        $calendartypes = $DB->get_records('apsolu_calendars_types');
        $sql = "SELECT c.id, c.typeid, ct.shortname
            FROM {apsolu_calendars} c
            JOIN {apsolu_calendars_types} ct ON c.typeid = ct.id";

        $calendars = $DB->get_records_sql($sql);

        $recordset = enrol_select_get_recordset_user_activity_enrolments($userid, $onlyactive = false);

        $acceptedenrolments = [];
        $otherenrolments = [];

        $current = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        require_login($course, $autologinguest = false);

        foreach ($recordset as $course) {
            $context = context_course::instance($course->id, MUST_EXIST);

            $canmanageenrolment = has_capability('enrol/select:manage', $context);
            $canmanagepastenrolment = has_capability('enrol/select:managepastenrolment', $context);

            $enrol = $DB->get_record('enrol', ['id' => $course->enrolid], '*', MUST_EXIST);
            if (isset($calendars[$enrol->customchar1]) === true) {
                $course->enrolname = $calendars[$enrol->customchar1]->shortname;
            }

            $courseurl = "";
            if ($courseid != $course->id) {
                $courseurl = new moodle_url('/course/view.php', ['id' => $course->id])->out(false);
            }

            $enrolurl = new moodle_url(
                '/enrol/select/manage.php',
                ['enrolid' => $course->enrolid],
                'enrol-' . $course->enrolid
            );

            $enrolment = [
                'enrolurl' => $enrolurl->out(false),
                'courseurl' => $courseurl,
                'coursefullname' => $course->fullname,
                'enrolname' => $course->enrolname,
                'rolename' => $roles[$course->roleid]->name,
                'editable' => $canmanageenrolment && ($enrol->customint8 > time() || $canmanagepastenrolment),
            ];

            if ($course->status == enrol_select_plugin::ACCEPTED) {
                $acceptedenrolments[] = $enrolment;
            } else {
                $enrolment['status'] = get_enrol_list_fieldvalue($course->status, 'listname');
                $enrolment['statuslevel'] = get_list_badge_color($course->status);
                $otherenrolments[] = $enrolment;
            }
        }
        $recordset->close();

        $result = [
            'count_accepted_enrolments' => count($acceptedenrolments),
            'str_accepted_status' => get_string_on_list_x(enrol_select_plugin::ACCEPTED, 'enrolments_on_listname_X'),
            'accepted_enrolments' => $acceptedenrolments,
            'count_other_enrolments' => count($otherenrolments),
            'other_enrolments' => $otherenrolments,
        ];

        return (object) $result;
    }

    /**
     * Renvoie la description du retour de la méthode.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'count_accepted_enrolments' => new external_value(PARAM_INT, 'user accepted enrolments number'),
            'str_accepted_status' => new external_value(PARAM_TEXT, 'accepted list status name', VALUE_OPTIONAL),
            'accepted_enrolments' => new external_multiple_structure(
                new external_single_structure([
                    'courseurl' => new external_value(PARAM_URL, 'course url'),
                    'coursefullname' => new external_value(PARAM_TEXT, 'course name'),
                    'enrolurl' => new external_value(PARAM_URL, 'course enrolments management url'),
                    'enrolname' => new external_value(PARAM_TEXT, 'enrolment method'),
                    'rolename' => new external_value(PARAM_TEXT, 'user enrolment role'),
                    'editable' => new external_value(PARAM_BOOL, 'user enrolment status'),
                ]),
            ),
            'count_other_enrolments' => new external_value(PARAM_INT, 'user not accepted enrolments number'),
            'other_enrolments' => new external_multiple_structure(
                new external_single_structure([
                    'courseurl' => new external_value(PARAM_URL, 'course url'),
                    'coursefullname' => new external_value(PARAM_TEXT, 'course name'),
                    'enrolurl' => new external_value(PARAM_URL, 'course enrolments management url'),
                    'enrolname' => new external_value(PARAM_TEXT, 'enrolment method'),
                    'rolename' => new external_value(PARAM_TEXT, 'user enrolment role'),
                    'editable' => new external_value(PARAM_BOOL, 'user enrolment status'),
                    'status' => new external_value(PARAM_TEXT, 'user enrolment status'),
                    'statuslevel' => new external_value(PARAM_TEXT, 'user enrolment status level'),
                ])
            ),
        ]);
    }
}
