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
use local_apsolu\core\course;
use local_apsolu\core\federation\course as FederationCourse;
use enrol_select_plugin;

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
     * @return \stdClass $userenrolments récapitulatif des inscriptions de l'utilisateur.
     */
    public static function execute(int $userid, int $courseid): \stdClass {
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

        // Récupère les "profils de cours" : l'utilisateur doit être inscrit au cours via une méthode select et avoir un rôle.
        $recordset = enrol_select_get_recordset_user_activity_enrolments($userid, $onlyactive = false);

        $acceptedenrolments = [];
        $otherenrolments = [];

        $current = course::get_record(['id' => $courseid], '*', MUST_EXIST);
        require_login($current, $autologinguest = false);

        $federationcourse = new FederationCourse();
        $federationcourseid = $federationcourse->get_courseid();

        if (empty($federationcourseid) === false) {
            // Numéro FFSU.
            $useradhesion = $DB->get_record('apsolu_federation_adhesions', ['userid' => $userid]);
            // TODO: afficher les bonnes informations sur la licence FFSU : qu'est-ce qui fait qu'on considère une licence
            // comme "validée", est-ce qu"on affiche l'état du paiement, est-ce qu'on regarde dans la table mdl_user_enrolments ?
            if ($useradhesion !== false) {
                $ffsulicencenumber = $useradhesion->federationnumber;
                $ffsulicencestatus = empty($useradhesion->timelicensed) ? "licence non attribuée" : "licence validée";
            }
        }

        // Un onglet par format d'activités, celui du cours actuellement consulté apparaît en premier et déplié.
        $currentcoursetypeid = $current->customfields['type']->get('intvalue');
        $courses = [$currentcoursetypeid => ['currenttype' => true]];
        foreach ($DB->get_records('apsolu_courses_types', [], $sort = 'sortorder') as $coursetype) {
            if (!isset($courses[$coursetype->id])) {
                $courses[$coursetype->id] = ['enrolments' => []];
                $courses[$coursetype->id]['currenttype'] = false;
            }
            $courses[$coursetype->id]['name'] = $coursetype->shortname;
            $courses[$coursetype->id]['enrolments']['accepted_enrolments'] = [];
            $courses[$coursetype->id]['enrolments']['other_enrolments'] = [];
        }
        foreach ($recordset as $useractivity) {
            // Un enrolement dans le cours FFSU peut remonter ici.
            if ($useractivity->id === $federationcourseid) {
                continue;
            }
            // Génère une erreur si le cours n'existe pas ou s'il n'est pas associé à un type de format d'activités.
            $enrolmentcourse = course::get_record(['id' => $useractivity->id], '*', MUST_EXIST);

            $context = \context_course::instance($enrolmentcourse->id, MUST_EXIST);
            $coursetypeid = $enrolmentcourse->customfields['type']->get('intvalue');

            $canmanageenrolment = has_capability('enrol/select:manage', $context);
            $canmanagepastenrolment = has_capability('enrol/select:managepastenrolment', $context);

            $enrol = $DB->get_record('enrol', ['id' => $useractivity->enrolid], '*', MUST_EXIST);
            if (isset($calendars[$enrol->customchar1]) === true) {
                $useractivity->enrolname = $calendars[$enrol->customchar1]->shortname;
            }

            // L'intitulé du cours est un lien, sauf pour le cours actuellement consultés (fermeture de la modale).
            $courseurl = "";
            if ($courseid != $enrolmentcourse->id) {
                $courseurl = new \moodle_url('/course/view.php', ['id' => $enrolmentcourse->id])->out(false);
            }

            $enrolurl = new \moodle_url(
                '/enrol/select/manage.php',
                ['enrolid' => $useractivity->enrolid],
                'enrol-' . $useractivity->enrolid
            );

            $enrolment = [
                'courseurl' => $courseurl,
                'coursefullname' => $useractivity->fullname,
                'rolename' => $roles[$useractivity->roleid]->name,
                'enrolname' => $useractivity->enrolname,
            ];

            // Affichage d'un lien pour accéder directement à la page de gestion des inscrits (selon autorisations).
            if ($canmanageenrolment && ($enrol->customint8 > time() || $canmanagepastenrolment)) {
                $enrolment['enrolurl'] = $enrolurl->out(false);
            }

            // Toutes les inscriptions pour ce format d'activités sont affichées (d'abord les inscriptions acceptées puis
            // les autres inscriptions).
            if ($useractivity->status == enrol_select_plugin::ACCEPTED) {
                $courses[$coursetypeid]['enrolments']['accepted_enrolments'][] = $enrolment;
            } else {
                $enrolment['status'] = get_enrol_list_fieldvalue($useractivity->status, 'listname');
                $enrolment['statuslevel'] = get_list_badge_color($useractivity->status);
                $courses[$coursetypeid]['enrolments']['other_enrolments'][] = $enrolment;
            }
        }

        $recordset->close();

        foreach ($courses as $typeid => $enrolments) {
            if (
                empty($enrolments['enrolments']['accepted_enrolments']) &&
                empty($enrolments['enrolments']['other_enrolments'])
            ) {
                unset($courses[$typeid]);
            }
        }

        $result = [
            'coursetypes' => $courses,
            'federation_number' => $ffsulicencenumber,
            'federation_status' => $ffsulicencestatus,
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
            'coursetypes' => new external_multiple_structure(
                new external_single_structure([
                    'currenttype' => new external_value(PARAM_BOOL, 'is currently opened course type'),
                    'name' => new external_value(PARAM_TEXT, 'course type name'),
                    'enrolments' => new external_single_structure([
                        'accepted_enrolments' => new external_multiple_structure(
                            new external_single_structure([
                                'courseurl' => new external_value(PARAM_URL, 'course url'),
                                'coursefullname' => new external_value(PARAM_TEXT, 'course name'),
                                'enrolurl' => new external_value(PARAM_URL, 'course enrolments management url', VALUE_OPTIONAL),
                                'enrolname' => new external_value(PARAM_TEXT, 'enrolment method'),
                                'rolename' => new external_value(PARAM_TEXT, 'user enrolment role'),
                            ])
                        ),
                        'other_enrolments' => new external_multiple_structure(
                            new external_single_structure([
                                'courseurl' => new external_value(PARAM_URL, 'course url'),
                                'coursefullname' => new external_value(PARAM_TEXT, 'course name'),
                                'enrolurl' => new external_value(PARAM_URL, 'course enrolments management url', VALUE_OPTIONAL),
                                'enrolname' => new external_value(PARAM_TEXT, 'enrolment method'),
                                'rolename' => new external_value(PARAM_TEXT, 'user enrolment role'),
                                'status' => new external_value(PARAM_TEXT, 'user enrolment status'),
                                'statuslevel' => new external_value(PARAM_TEXT, 'user enrolment status level'),
                            ])
                        ),
                    ]),
                ]),
            ),
            'federation_number' => new external_value(PARAM_TEXT, 'user federation licence number'),
            'federation_status' => new external_value(PARAM_TEXT, 'user federation licence status'),
        ]);
    }
}
