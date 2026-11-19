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

defined('MOODLE_INTERNAL') || die();

use local_apsolu\core\federation\course as FederationCourse;
use local_apsolu\core\customfields;

require_once($CFG->dirroot . '/enrol/select/locallib.php');

/**
 * Classe principale du module enrol_select.
 *
 * @package    enrol_select
 * @copyright  2016 Université Rennes 2
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\AllowDynamicProperties]
class enrol_select_plugin extends enrol_plugin {
    /**
     * Code du statut accepté.
     */
    const ACCEPTED = '0';

    /**
     * Code du statut de la liste principale.
     */
    const MAIN = '2';

    /**
     * Code du statut de la liste secondaire.
     */
    const WAIT = '3';

    /**
     * Code du statut désinscrit.
     */
    const DELETED = '4';

    /** @var array Tableau contenant les paramètres par défaut lors de l'installation du module enrol_select. */
    public static $initialsettings = [
        'default_cohorts' => '', // Cohortes par défaut.
        'default_roles' => 5, // Rôles par défaut.
        'default_cards' => '', // Paiements par défaut.
        'default_customint1' => 20, // Maximum de place sur la liste principale.
        'default_customint2' => 10, // Maximum de place sur la liste d'attente.
        'default_customint3' => 0, // Activer les quotas.
        'default_customdec1' => 0, // Délai de paiement.
        'default_customchar1' => 0, // Type de calendrier.
        'default_customchar2' => 0, // Remontée de liste automatique.
        'default_customchar3' => self::MAIN, // Liste sur laquelle inscrire les étudiants.
        'default_customtext1' => '', // Message de bienvenue pour les inscrits sur liste des acceptés.
        'default_customtext2' => '', // Message de bienvenue pour les inscrits sur liste principale.
        'default_customtext3' => '', // Message de bienvenue pour les inscrits sur liste complémentaire.
    ];

    /** @var array Tableau indexé avec les constantes de classe ACCEPTED, MAIN, WAIT et DELETED. */
    public static $states = [
        self::ACCEPTED => 'accepted',
        self::MAIN => 'main',
        self::WAIT => 'wait',
        self::DELETED => 'deleted',
    ];

    /** @var array Tableau indexé avec les différents champs paramétrables pour le statut de l'inscription
     * et leurs correspondances avec les clés du fichier de langue et de la table de configuration du plugin.
     * Dans la chaîne qui représente la clé, "%s" correspondra au statut ("accepted", "main" etc.).
     */
    public static $listformats = [
        'status' => 'list_%s',
        'statusshort' => '%s_list_short',
        'listname' => '%s_list',
        'description' => '%s_description',
    ];

    /**
     * Retourne la liste des différents champs paramétrables pour décrire le statut de l'inscription.
     *
     * @return array les différents champs utilisables pour évoquer le statut de l'inscription
     * (nom de la liste, statut de l'inscription, version courte..).
     */
    public static function get_listformats(): array {
        return array_keys(self::$listformats);
    }

    /**
     * Renvoie le statut qui correspond au code numérique parmi les constantes de la classe.
     *
     * @param string|int $code la valeur numérique du statut défini par les constantes de la classe.
     * @return string|false renvoie le statut sous forme de texte ('accepted', 'deleted'..),
     *                          ou false si le $code n'est pas trouvé parmi les constantes de la classe.
     */
    public static function get_state_from_code($code) {
        switch ($code) {
            case self::ACCEPTED:
            case self::MAIN:
            case self::WAIT:
            case self::DELETED:
                return self::$states[$code];
            default:
                return false;
        }
    }

    /**
     * Retourne la clé permettant de définir et de charger les chaînes de caractères (fichier de langue et table de configuration
     *  du plugin) correspondant à un format du statut d'inscription.
     *
     * @param string|null $listformat le format à utiliser (valeur parmi les clés du tableau $listformats).
     * @return string|null la clé à utiliser pour charger la chaîne de caractères.
     *                          Renvoie null si $listformat n'est pas une clé du tableau $listformats.
     */
    public static function get_settingname($listformat): string|null {
        if (isset(self::$listformats[$listformat])) {
            return self::$listformats[$listformat];
        }
        return null;
    }


    /**
     * Enregistre les modifications apportées aux chaînes de caractères custom pour le plugin enrol select.
     *
     * @param int|string $state le code correspondant au statut dans les constantes de la classe.
     * @param array $new liste des valeurs envoyées pour les différents champs.
     * @param array $old liste des valeurs de départ pour les différents champs.
     * @param array $defaults liste des valeurs par défaut pour les différents champs.
     *
     * @return boolean true s'il y a eu au moins une modification (ajout, update ou suppression) dans la configuration du plugin
     */
    public static function save_state_custom_strings(int|string $state, array $new, array $old, array $defaults): bool {

        $haschanged = false; // Témoin de la modification effective du tableau des chaînes définies pour les différentes listes.
        foreach ($old as $strtype => $strold) {
            $strname = get_enrol_list_strname($state, $strtype);
            // On enregistre la chaîne en minuscules sauf pour la "version courte".
            $newconfig = $strtype != 'statusshort' ? strtolower($new[$strtype]) : $new[$strtype];
            $oldconfig = $strold;

            // On vérifie que la valeur a été modifiée.
            if ($newconfig != $strold) {
                // Un champ vide entraine la suppression de la valeur custom (configuration du plugin).
                // Idem si la valeur soumise est identique à la valeur par défaut.
                if (empty($newconfig) == true || $newconfig == $defaults[$strtype]) {
                    // Un champ vide entraine la suppression de la valeur custom (configuration du plugin).
                    // Idem si la valeur soumise est identique à la valeur par défaut.
                    unset_config($strname, 'enrol_select');

                    $haschanged = true;
                } else { // La valeur est ni vide ni identique à la valeur par défaut.
                    // Dans le cas du nom de la liste et de sa description, il y a un formatage qui est appliqué
                    // en amont, il faut donc extraire la chaîne non formatée.
                    if ($strtype == 'listname' || $strtype == 'description') {
                        $newconfig = unformatstr($newconfig, $strtype);
                        $oldconfig = unformatstr($oldconfig, $strtype);
                    }
                    if ($newconfig != false && set_config($strname, $newconfig, 'enrol_select')) {
                        add_to_config_log($strname, $oldconfig, $newconfig, 'enrol_select');
                        $haschanged = true;
                    }
                }
            }
        }

        return $haschanged;
    }

    /**
     * Returns name of this enrol plugin.
     *
     * @return string
     */
    public function get_name() {
        // Second word in class is always enrol name, sorry, no fancy plugin names with _.
        return 'select';
    }

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass|null $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance->name) === false) {
            // Affiche le nom personnalisé de la méthode d'inscription.
            $context = context_course::instance($instance->courseid);
            return format_string($instance->name, true, ['context' => $context]);
        }

        if (empty($instance->customchar1) === false) {
            // Affiche le nom du calendrier.
            $calendar = $DB->get_record('apsolu_calendars', ['id' => $instance->customchar1]);
            if ($calendar !== false) {
                return $calendar->name;
            }
        }

        // Affiche le nom générique de la méthode d'inscription.
        return get_string('pluginname', 'enrol_select');
    }

    /**
     * Returns edit icons for the page with list of instances.
     *
     * @param stdClass $instance
     *
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'select') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = [];

        if (has_capability('enrol/select:enrol', $context) || has_capability('enrol/select:unenrol', $context)) {
            $managelink = new moodle_url("/enrol/select/manage.php", ['enrolid' => $instance->id]);

            $label = get_string('enrolusers', 'enrol_manual');
            $pixicon = new pix_icon('t/enrolusers', $label, 'core', ['class' => 'iconsmall']);
            $icons[] = $OUTPUT->action_icon($managelink, $pixicon);
        }

        if (has_capability('enrol/select:config', $context)) {
            $editlink = new moodle_url("/enrol/select/edit.php", ['courseid' => $instance->courseid, 'id' => $instance->id]);
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon(
                't/edit',
                get_string('edit'),
                'core',
                ['class' => 'iconsmall']
            ));
        }

        return $icons;
    }

    /**
     * Méthode permettant de récupérer la liste des utilisateurs inscrits à une session de cours, et de vérifier
     * si l'inscription est autorisée (rôle, cohorte, contexte...)
     *
     * @param stdClass $session
     * @param bool|null $getinvalid Activer la remontée des inscriptions non acceptées (activé par défaut).
     * @param bool|null $getpastenrolments Activer la remontée des inscriptions terminées (activé par défaut).
     *
     * @return array Tableau contenant les utilisateurs indéxé par userid.
     */
    public static function get_session_enrolled_users(
        $session,
        ?bool $getinvalid = true,
        ?bool $getpastenrolments = true
    ) {
        global $DB;

        $customfields = customfields::getCustomFields();

        $where = [];
        $where[] = 'e.courseid = :courseid';
        $where[] = 'e.status = 0'; // Seulement les méthodes d'inscription actives.

        $params = [];
        $params['courseid'] = $session->courseid;

        // On ne veut que les inscriptions acceptées.
        if ($getinvalid === false) {
            $where[] = '(ue.status = :status)';
            $params['status'] = self::ACCEPTED;
        }

        $where[] = '(ue.timestart <= :timestart OR ue.timestart = 0)';
        $params['timestart'] = $session->sessiontime;

        if ($getpastenrolments === false) {
            $where[] = '(ue.timeend >= :timeend OR ue.timeend = 0)';
            $params['timeend'] = $session->sessiontime;
        }

        // Récupérer toutes les inscriptions.
        $sql = "SELECT ue.id AS ueid, ue.status, ue.timestart, ue.timeend, ue.enrolid, ue.userid,
                    ra.roleid AS roleid FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {role_assignments} ra ON ue.userid = ra.userid AND e.id = ra.itemid
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY ue.userid, e.sortorder DESC";

        $enrolments = $DB->get_records_sql($sql, $params);

        if (empty($enrolments) == false) {
            // Informations du profil utilisateur.
            [$insql, $params] = $DB->get_in_or_equal(array_keys($enrolments), SQL_PARAMS_NAMED, 'inc');

            $sql = "SELECT u.*, uid1.data AS apsolusesame
                    FROM {user} u
                LEFT JOIN {user_info_data} uid1 ON u.id = uid1.userid AND uid1.fieldid = :apsolusesame
                    JOIN {user_enrolments} ue ON u.id = ue.userid
                    WHERE ue.id $insql
                ORDER BY u.lastname, u.firstname";

            $params['apsolusesame'] = $customfields['apsolusesame']->id;
            $userprofiles = $DB->get_records_sql($sql, $params);
        }

        // Créer un tableau avec une inscription par utilisateur : vérifier si l'inscription est autorisée, et
        // s'il y a plusieurs inscriptions au cours on conserve celle qui est valide et/ou active.
        $users = [];

        foreach ($enrolments as $enrolment) {
            $enrolment->isactive = $enrolment->timeend == 0 || $enrolment->timeend >= $session->sessiontime;
            $enrolment->isvalid = $enrolment->status == self::ACCEPTED;

            // Il y a déjà une inscription pour cet utilisateur ? On compare le caractère valide / actif des deux inscriptions.
            if (isset($users[$enrolment->userid])) {
                if ($getpastenrolments == false) {
                    // Si la remontée des inscriptions du semestre précédent n'est pas activé,
                    // il ne devrait pas y avoir plusieurs inscriptions pour un même utilisateur.
                    if (defined('BEHAT_SITE_RUNNING') === false) {
                        debugging(sprintf(
                            "L’utilisateur #%s a plusieurs inscriptions enregistrées pour la même période sur le même cours %s",
                            $enrolment->userid,
                            $session->id
                        ), $level = DEBUG_DEVELOPER);
                    }
                }
                // Si les 2 méthodes sont équivalentes, celle qui a la valeur "sortorder" la plus haute est conservée
                // (c'est la première dans le tableau grâce au tri inversé sur la colonne en question dans la requête).
                $current = $users[$enrolment->userid]->enrolment; // Inscription déjà associée au profil.
                if (
                    ( (int) $current->isvalid + (int) $current->isactive ) >=
                    ( (int) $enrolment->isactive + (int) $enrolment->isvalid)
                ) {
                    continue;
                }
            } else {
                $users[$enrolment->userid] = $userprofiles[$enrolment->userid];
            }

            // Evaluer si l'inscription est autorisée.

            // 1. On vérifie si l'étudiant est dans une cohorte et une population autorisées à s'inscrire à ce cours
                // avec ce rôle (indépendemment de la validité du rôle).
            $enrolment->isuserauthorized = true;

            $sql = "SELECT COUNT(cm.cohortid) FROM {cohort_members} cm
                JOIN {enrol_select_cohorts} esc ON cm.cohortid = esc.cohortid
                JOIN {apsolu_colleges_members} acm ON cm.cohortid = acm.cohortid
                JOIN {apsolu_colleges} ac ON acm.collegeid = ac.id
                WHERE cm.userid = :userid AND esc.enrolid = :enrolid AND ac.roleid = :userrole";

            $params = ['userid' => $enrolment->userid, 'enrolid' => $enrolment->enrolid, 'userrole' => $enrolment->roleid];

            if (empty($DB->get_fieldset_sql($sql, $params)[0])) {
                $enrolment->isuserauthorized = false;

                if (defined('BEHAT_SITE_RUNNING') === false) {
                    debugging(sprintf(
                        "L’inscription (mdl_user_enrolments.id #%s) n’est pas correcte : " .
                        "l'utilisateur n’est dans aucune cohorte autorisée à s’inscrire à ce cours avec ce rôle",
                        $enrolment->ueid
                    ), $level = DEBUG_DEVELOPER);
                }
            }

            // 2. On vérifie si le rôle attribué est cohérent avec la méthode et le contexte.
            $enrolment->isroleauthorized = true;

            $sql = "SELECT COUNT(ra.id) FROM {role_assignments} ra
                JOIN {enrol_select_roles} esr ON ra.roleid = esr.roleid AND esr.enrolid = ra.itemid
                JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.contextlevel = 50
                JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                WHERE ra.userid = :userid AND ra.itemid = :enrolid AND ctx.instanceid = :courseid";

            $params = ['userid' => $enrolment->userid, 'enrolid' => $enrolment->enrolid, 'courseid' => $session->courseid];

            if (empty($DB->get_fieldset_sql($sql, $params)[0]) == true) {
                $enrolment->isroleauthorized = false;
                if (defined('BEHAT_SITE_RUNNING') === false) {
                    debugging(sprintf(
                        "L’inscription (mdl_user_enrolments.id #%s) n’est pas correcte : " .
                        "l’utilisateur n’est associé à aucun rôle valide dans ce contexte.",
                        $enrolment->ueid
                    ), $level = DEBUG_DEVELOPER);
                }
            }

            // On ajoute les informations de l'inscription au profil utilisateur.
            $users[$enrolment->userid]->enrolment = $enrolment;
        }

        return $users;
    }

    /**
     * This returns false for backwards compatibility, but it is really recommended.
     *
     * @since Moodle 3.1
     * @return boolean
     */
    public function use_standard_editing_ui() {
        // TODO: mettre à true pour afficher les méthodes dans le menu déroulant.
        return false;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        // TODO: ne pas utiliser ce hook moche.
        redirect(new moodle_url('/enrol/select/edit.php', ['courseid' => $instance->courseid, 'id' => $instance->id]));
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     * @return void
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        // TODO.
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     *
     * @param int $courseid
     *
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/select:config', $context)) {
            return null;
        }
        // Multiple instances supported - different roles with different password.
        return new moodle_url('/enrol/select/edit.php', ['courseid' => $courseid]);
    }

    /**
     * Returns defaults for new instances.
     *
     * @return array
     */
    public function get_instance_defaults() {
        $instance = get_config('enrol_select');

        $fields = [];
        $fields['status'] = ENROL_INSTANCE_ENABLED; // Enable method or not.
        $fields['cohorts'] = explode(',', $instance->default_cohorts); // Cohortes par défaut.
        $fields['roles'] = explode(',', $instance->default_roles); // Rôles par défaut.
        $fields['cards'] = explode(',', $instance->default_cards); // Paiements par défaut.
        $fields['customint1'] = $instance->default_customint1; // Maximum de place sur la liste principale.
        $fields['customint2'] = $instance->default_customint2; // Maximum de place sur la liste d'attente.
        $fields['customint3'] = $instance->default_customint3; // Activer les quotas.
        $fields['customint4'] = 0; // Reenrol start date.
        $fields['customint5'] = 0; // Reenrol end date.
        $fields['customint6'] = 0; // Reenrol select_enrol instance.
        $fields['customint7'] = 0; // Course start date.
        $fields['customint8'] = 0; // Course end date.
        $fields['customdec1'] = $instance->default_customdec1; // Délai de paiement.
        $fields['customchar1'] = $instance->default_customchar1; // Type de calendrier.
        $fields['customchar2'] = $instance->default_customchar2; // Remontée de liste automatique.
        $fields['customchar3'] = $instance->default_customchar3; // Liste sur laquelle inscrire les étudiants.
        $fields['customtext1'] = $instance->default_customtext1; // Message de bienvenue pour les inscrits sur liste des acceptés.
        $fields['customtext2'] = $instance->default_customtext2; // Message de bienvenue pour les inscrits sur liste principale.
        $fields['customtext3'] = $instance->default_customtext3; // Message de bienvenue pour les inscrits sur liste complémentaire.

        return $fields;
    }

    /**
     * Retourne les valeurs par défaut lors de l'installation du module enrol_select.
     *
     * @return void
     */
    public static function setup_initial_settings(): void {
        $instance = get_config('enrol_select');

        foreach (self::$initialsettings as $name => $value) {
            if (isset($instance->$name) === true) {
                continue;
            }

            set_config($name, $value, 'enrol_select');
        }
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     *
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);

        return has_capability('enrol/select:config', $context);
    }

    /**
     * Return true if we can add a new instance to this course.
     *
     * @param int $courseid
     *
     * @return boolean
     */
    public function can_add_instance($courseid) {
        global $DB;

        $context = context_course::instance($courseid, MUST_EXIST);
        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/manual:config', $context)) {
            return false;
        }

        if ($DB->record_exists('enrol', ['courseid' => $courseid, 'enrol' => 'manual'])) {
            // Multiple instances not supported.
            return false;
        }

        return true;
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param object $instance
     *
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/select:config', $context);
    }

    /**
     * Retourne le code de la liste dans laquelle sera enregistré le prochain inscrit.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription.
     * @param object $user     Objet représentant le prochain utilisateur inscrit.
     *
     * @return string|false Retourne false si il n'y a plus de place cette méthode d'inscription par voeux.
     */
    public function get_available_status($instance, $user) {
        global $DB;

        if (empty($instance->customint3) === true) {
            // Lorsque les quota ne sont pas activés, on retourne le code de la liste principale.
            return self::get_default_enrolment_list($instance);
        }

        // Si l'utilisateur est déjà inscrit on retourne la liste d'inscription actuelle.
        if (($userstatus = enrol_select_get_user_enrolment_status($instance, $user)) !== false) {
            return $userstatus;
        }

        // Détermine si il y a déjà des utilisateurs sur liste complémentaire.
        $params = ['enrolid' => $instance->id, 'status' => self::WAIT];
        $waitlistenrolements = $DB->get_records('user_enrolments', $params, '', 'userid');
        $this->count_wait_list_enrolements = count($waitlistenrolements);

        if (isset($user, $waitlistenrolements[$user->id])) {
            unset($waitlistenrolements[$user->id]);
            $countwaitlistenrolements = $this->count_wait_list_enrolements - 1;
        } else {
            $countwaitlistenrolements = $this->count_wait_list_enrolements;
        }

        if ($countwaitlistenrolements >= $instance->customint2 && empty($instance->customint2) === false) {
            // Il n'y a plus de place disponible sur la liste complémentaire
            // et les quotas sont définis pour la liste complémentaire.
            return false;
        }

        if ($countwaitlistenrolements !== 0) {
            // Il y a déjà des utilisateurs sur liste complémentaire. On est sûr qu'il reste de la place uniquement ici.
            return self::WAIT;
        }

        // Détermine si il y a déjà des utilisateurs sur la liste des acceptés et la liste principale.
        $sql = "SELECT userid" .
            " FROM {user_enrolments}" .
            " WHERE enrolid = :enrolid" .
            " AND status IN (:accepted, :main)";
        $params = ['enrolid' => $instance->id, 'accepted' => self::ACCEPTED, 'main' => self::MAIN];
        $mainlistenrolements = $DB->get_records_sql($sql, $params);
        $this->count_main_list_enrolements = count($mainlistenrolements);

        if (isset($user, $mainlistenrolements[$user->id])) {
            unset($mainlistenrolements[$user->id]);
            $countmainlistenrolements = $this->count_main_list_enrolements - 1;
        } else {
            $countmainlistenrolements = $this->count_main_list_enrolements;
        }

        if ($countmainlistenrolements < $instance->customint1 && empty($instance->customint1) === false) {
            // Il reste des places disponibles sur liste principale et les quotas sont définis pour la liste principale.
            return self::get_default_enrolment_list($instance);
        }

        // Cas où la liste principale est pleine et la liste complémentaire est vide.
        if (empty($instance->customint2) === false) {
            // Si le quota sur la liste complémentaire n'est pas vide.
            return self::WAIT;
        }

        // Il n'y a pas de liste complémentaire. Il n'est plus possible de s'inscrire.
        return false;
    }

    /**
     * Retourne le code de la liste sur laquelle est inscrit l'utilisateur par défaut.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription.
     *
     * @return int Retourne soit self::ACCEPTED, soit self::MAIN.
     */
    public static function get_default_enrolment_list($instance) {
        if ($instance->customchar3 === self::ACCEPTED) {
            return self::ACCEPTED;
        }

        return self::MAIN;
    }

    /**
     * Retourne les rôles disponibles pour une méthode d'inscription donnée et un contexte.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     * @param object $context  Objet représentant un contexte.
     *
     * @return array Retourne un tableau de rôles.
     */
    public function get_roles($instance, $context) {
        global $DB;

        $roles = $DB->get_records('enrol_select_roles', ['enrolid' => $instance->id], '', 'roleid');
        foreach (get_assignable_roles($context, ROLENAME_BOTH) as $id => $role) {
            if (!isset($roles[$id])) {
                unset($roles[$id]);
            } else {
                $roles[$id]->id = $id;
                $roles[$id]->name = $role;
            }
        }

        $roles = array_values($roles);

        return $roles;
    }

    /**
     * Retourne le rôle d'un utilisateur pour une méthode d'inscription donnée.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     * @param int    $userid   Identifiant de l'utilisateur. Si il n'est pas fourni,
     *                         c'est l'identifiant de l'utilisateur courant qui sera utilisé.
     *
     * @return object|false Retourne un object du rôle ou false si l'utilisateur n'est pas inscrit via cette méthode.
     */
    public function get_user_role($instance, $userid = null) {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $sql = "SELECT r.*
            FROM {role} r
            JOIN {role_assignments} ra ON r.id = ra.roleid
            JOIN {context} ctx ON ctx.id = ra.contextid
            JOIN {enrol} e ON ctx.instanceid = e.courseid AND e.id = ra.itemid
            JOIN {user_enrolments} ue ON e.id = ue.enrolid AND ue.userid = ra.userid
            WHERE e.id = :enrolid
            AND e.enrol = 'select'
            AND e.status = 0
            AND ue.userid = :userid
            AND ctx.contextlevel = 50";

        $params = ['enrolid' => $instance->id, 'userid' => $userid];

        $roles = role_fix_names($DB->get_records_sql($sql, $params));

        return current($roles);
    }

    /**
     * Retourne les rôles disponibles pour un utilisateur et une méthode d'inscription donnée.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     * @param int    $userid   Identifiant de l'utilisateur. Si il n'est pas fourni,
     *                         c'est l'identifiant de l'utilisateur courant qui sera utilisé.
     *
     * @return array Retourne un tableau de rôles.
     */
    public function get_available_user_roles($instance, $userid = null) {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $sql = "SELECT DISTINCT r.*
                  FROM {role} r
                  JOIN {enrol_select_roles} esr ON r.id = esr.roleid
                  JOIN {enrol} e ON e.id = esr.enrolid
                  JOIN {enrol_select_cohorts} esc ON e.id = esc.enrolid
                  JOIN {cohort_members} cm ON cm.cohortid = esc.cohortid
                  JOIN {apsolu_colleges_members} acm ON acm.cohortid = cm.cohortid
                  JOIN {apsolu_colleges} ac ON ac.id = acm.collegeid AND r.id = ac.roleid
                 WHERE e.id = :enrolid
                   AND e.enrol = 'select'
                   AND e.status = 0  -- Active.
                   AND cm.userid = :userid
              ORDER BY r.sortorder";
        $params = ['enrolid' => $instance->id, 'userid' => $userid];

        return role_fix_names($DB->get_records_sql($sql, $params));
    }

    /**
     * Fonction à documenter (TODO).
     *
     * @param object      $instance Objet de l'instance de la méthode d'inscription
     * @param null|object $user     Identifiant de l'utilisateur. Si il n'est pas fourni,
     *                              c'est l'identifiant de l'utilisateur courant qui sera utilisé.
     *
     * @return void.
     */
    public function set_available_status($instance, $user = null) {
        global $DB;

        debugging(sprintf('%s() is deprecated. Please see' .
            ' enrol_select_plugin::get_available_status() method instead.', __METHOD__), DEBUG_DEVELOPER);

        $this->available_status = [];

        // Check main list.
        $sql = "SELECT userid" .
            " FROM {user_enrolments}" .
            " WHERE enrolid = :enrolid" .
            " AND status IN (0, 2)";
        $mainlistenrolements = $DB->get_records_sql($sql, ['enrolid' => $instance->id]);
        $this->count_main_list_enrolements = count($mainlistenrolements);

        if (isset($user, $mainlistenrolements[$user->id])) {
            unset($mainlistenrolements[$user->id]);
            $countmainlistenrolements = $this->count_main_list_enrolements - 1;
        } else {
            $countmainlistenrolements = $this->count_main_list_enrolements;
        }

        if ($countmainlistenrolements < $instance->customint1) {
            // Some slots are available on main list.
            $this->available_status[] = '2';
        }

        // Check wait list.
        $params = ['enrolid' => $instance->id, 'status' => self::WAIT];
        $waitlistenrolements = $DB->get_records('user_enrolments', $params, '', 'userid');
        $this->count_wait_list_enrolements = count($waitlistenrolements);

        if (isset($user, $waitlistenrolements[$user->id])) {
            unset($waitlistenrolements[$user->id]);
            $countwaitlistenrolements = $this->count_wait_list_enrolements - 1;
        } else {
            $countwaitlistenrolements = $this->count_wait_list_enrolements;
        }

        if ($countwaitlistenrolements < $instance->customint2) {
            // Some slots are available on wait list.
            $this->available_status[] = '3';
        }
    }

    /**
     * Détermine si le cours est ouvert.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     *
     * @return bool Vrai si le cours est ouvert.
     */
    public function is_active_course_period($instance) {
        $today = time();

        $opening = (empty($instance->customint7) === true || $instance->customint7 <= $today);
        $closing = (empty($instance->customint8) === true || $instance->customint8 >= $today);

        return ($opening && $closing);
    }

    /**
     * Détermine si le cours est passé.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     *
     * @return bool Vrai si le cours est passé.
     */
    public function is_past_course($instance) {
        return (empty($instance->customint8) === false && $instance->customint8 < time());
    }

    /**
     * Détermine si le cours est à venir.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     *
     * @return bool Vrai si le cours est à venir.
     */
    public function is_upcoming_course($instance) {
        return (empty($instance->customint7) === false && $instance->customint7 > time());
    }

    /**
     * Détermine si les inscriptions sont ouvertes.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     *
     * @return bool Vrai si les inscriptions sont ouvertes.
     */
    public function is_active_enrolment_period($instance) {
        $today = time();

        $opening = (empty($instance->enrolstartdate) === true || $instance->enrolstartdate <= $today);
        $closing = (empty($instance->enrolenddate) === true || $instance->enrolenddate >= $today);

        return ($opening && $closing);
    }

    /**
     * Détermine si les inscriptions sont ouvertes.
     *
     * @param object $instance Objet de l'instance de la méthode d'inscription
     *
     * @return bool Vrai si les inscriptions sont ouvertes.
     */
    #[\core\attribute\deprecated('enrol_select_plugin::is_active_enrolment_period($instance)')]
    public function is_enrol_period_active($instance) {
        \core\deprecation::emit_deprecation(__METHOD__);

        $this->is_active_enrolment_period($instance);
    }

    /**
     * Détermine si un utilisateur peut s'inscrire à une instance avec un rôle donné.
     *
     * @param object     $instance Objet de l'instance de la méthode d'inscription.
     * @param object     $user     Objet représentant l'utilisateur.
     * @param int|string $roleid   Identifiant d'un rôle.
     *
     * @return bool Vrai si les inscriptions sont ouvertes.
     */
    public function can_enrol($instance, $user, $roleid) {
        global $CFG, $DB;

        $today = time();

        // Check opening register period.
        if ($instance->enrolstartdate !== '0' && $instance->enrolstartdate > $today) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' not opened yet.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check closing register period.
        if ($instance->enrolenddate !== '0' && $instance->enrolenddate < $today) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' already closed.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check cohorts.
        $usercohorts = $DB->get_records('cohort_members', ['userid' => $user->id]);
        $enrolcohorts = $DB->get_records('enrol_select_cohorts', ['enrolid' => $instance->id], '', 'cohortid');

        $found = false;
        foreach ($usercohorts as $cohort) {
            if (isset($enrolcohorts[$cohort->cohortid])) {
                $found = true;
                break;
            }
        }

        if ($found !== true) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ': ' . $user->username . ' and enrol cohort mismatch.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        $federationcourse = new FederationCourse();
        if ($instance->courseid === $federationcourse->get_courseid()) {
            // Bidouille moche pour gérer l'inscription à la FFSU.
            return true;
        }

        if (isset($CFG->is_siuaps_rennes) === true) {
            // Dirty hack pour les activités complémentaires !
            // À virer, et mettre des auto-inscriptions à la place.
            if ($roleid == 5 || in_array($instance->courseid, ['249', '250'], $strict = true) === true) {
                return true;
            }
        }

        // Check available slots.
        if ($this->get_available_status($instance, $user) === false) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' n\'a plus aucune place disponible.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check user limit.
        $userchoices = enrol_select_get_sum_user_choices($userid = null, $count = true);
        $available = false;
        foreach ($userchoices as $choice) {
            if ($choice->roleid != $roleid) {
                continue;
            }

            if ($choice->maxwish > 0 && $choice->count < $choice->maxwish) {
                $available = true;
                break;
            }
        }

        if ($available === false) {
            $parameters = ['userid' => $user->id, 'roleid' => $roleid];
            $message = get_string('the_user_X_has_reached_their_wish_limit_for_the_role_Y', 'enrol_select', $parameters);

            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($message, $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check role.
        if ($DB->get_record('enrol_select_roles', ['enrolid' => $instance->id, 'roleid' => $roleid]) === false) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ': roleid #' . $roleid . ' is not available.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        return true;
    }

    /**
     * Détermine si un utilisateur peut se réinscrire.
     *
     * @param object          $instance Objet de l'instance de la méthode d'inscription.
     * @param null|int|string $userid   Identifiant d'un utilisateur.
     * @param null|int|string $roleid   Identifiant d'un rôle.
     *
     * @return bool Vrai si les inscriptions sont ouvertes.
     */
    public function can_reenrol($instance, $userid = null, $roleid = null) {
        global $DB, $USER;

        $today = time();

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Check reenrol enabled.
        if (empty($instance->customint6)) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' reenrol not enabled.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check reenrol exists.
        $enrol = $DB->get_record('enrol', ['id' => $instance->customint6, 'enrol' => 'select']);
        if ($enrol === false) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging(
                    $this->get_name() . ' reenrol id #' . $instance->customint6 . ' does not exist',
                    $level = DEBUG_DEVELOPER
                );
            }
            return false;
        }

        // Check opening reenrol period.
        if ($instance->customint4 !== '0' && $instance->customint4 > $today) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' not opened yet.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check closing reenrol period.
        if ($instance->customint5 !== '0' && $instance->customint5 < $today) {
            if (defined('BEHAT_SITE_RUNNING') === false) {
                debugging($this->get_name() . ' already closed.', $level = DEBUG_DEVELOPER);
            }
            return false;
        }

        // Check cohorts.
        if ($instance->customint3 === '1') {
            $usercohorts = $DB->get_records('cohort_members', ['userid' => $userid]);
            $enrolcohorts = $DB->get_records('enrol_select_cohorts', ['enrolid' => $instance->id], '', 'cohortid');

            $found = false;
            foreach ($usercohorts as $cohort) {
                if (isset($enrolcohorts[$cohort->cohortid])) {
                    $found = true;
                    break;
                }
            }

            if ($found !== true) {
                if (defined('BEHAT_SITE_RUNNING') === false) {
                    debugging($this->get_name() . ': userid #' . $userid . ' and enrol cohort mismatch.', $level = DEBUG_DEVELOPER);
                }
                return false;
            }
        }

        // We don't check available slots.
        // We don't check user limit.

        // Check role.
        if ($roleid !== null) {
            if ($DB->get_record('enrol_select_roles', ['enrolid' => $instance->id, 'roleid' => $roleid]) === false) {
                if (defined('BEHAT_SITE_RUNNING') === false) {
                    debugging($this->get_name() . ': roleid #' . $roleid . ' is not available.', $level = DEBUG_DEVELOPER);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Méthode permettant l'inscription d'un utilisateur à une instance.
     *
     * @param stdClass        $instance      Objet de l'instance de la méthode d'inscription.
     * @param int|string      $userid        Identifiant d'un utilisateur.
     * @param null|int|string $roleid        Identifiant d'un rôle.
     * @param int|string      $timestart     Timestamp de début de cours.
     * @param int|string      $timeend       Timestamp de fin de cours.
     * @param null|int|string $status        État de l'inscription (accepté, liste principale, liste secondaire, supprimé).
     * @param null|bool       $recovergrades Récupérer les notes ?
     *
     * @return void.
     */
    public function enrol_user(
        stdClass $instance,
        $userid,
        $roleid = null,
        $timestart = 0,
        $timeend = 0,
        $status = null,
        $recovergrades = null
    ) {
        global $DB, $USER;

        // La méthode parent::enrol_user() ne remplace pas les rôles, mais cumul.
        // Il faut donc faire un traitement différent si il s'agit juste d'un changement de rôle.
        $enrolled = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if ($enrolled === false) {
            if ($timestart === 0) {
                $timestart = $instance->customint7;
            }

            if ($timeend === 0) {
                $timeend = $instance->customint8;
            }

            parent::enrol_user($instance, $userid, $roleid, $timestart, $timeend, $status, $recovergrades);

            // Ajoute une tâche pour contrôler le paiement après l'inscription.
            $instance->customdec1 = intval($instance->customdec1);
            if (empty($instance->customdec1) === false && $status === self::ACCEPTED) {
                $customdata = (object) ['courseid' => $instance->courseid, 'enrolid' => $instance->id];

                $task = new enrol_select\task\check_enrolment_payment();
                $task->set_next_run_time(time() + $instance->customdec1);
                $task->set_custom_data($customdata);
                $task->set_userid($userid);

                core\task\manager::queue_adhoc_task($task);
            }

            // Notifie le nouvel inscrit.
            if ($userid === $USER->id) {
                $message = '';
                switch ($status) {
                    case self::ACCEPTED:
                        $message = $instance->customtext1;
                        break;
                    case self::MAIN:
                        $message = $instance->customtext2;
                        break;
                    case self::WAIT:
                        $message = $instance->customtext3;
                        break;
                }

                if (empty($message) === false) {
                    $course = $DB->get_record('course', ['id' => $instance->courseid]);

                    $eventdata = new \core\message\message();
                    $eventdata->name = 'select_notification';
                    $eventdata->component = 'enrol_select';
                    $eventdata->userfrom = get_admin();
                    $eventdata->userto = $userid;
                    $params = format_string($course->fullname, $striplinks = true, $course->id);
                    $eventdata->subject = get_string('enrolment_to', 'enrol_select', $params);
                    $eventdata->fullmessage = $message;
                    $eventdata->fullmessageformat = FORMAT_HTML;
                    $eventdata->fullmessagehtml = $message;
                    $eventdata->smallmessage = '';
                    $eventdata->notification = 1;
                    $eventdata->courseid = $course->id;

                    message_send($eventdata);
                }
            }

            return;
        }

        // Traite le cas où on souhaite juste modifier le statut.
        if ($status !== null) {
            $sql = "UPDATE {user_enrolments}" .
                " SET status = :status, timemodified = :now" .
                " WHERE enrolid = :enrolid" .
                " AND userid = :userid";
            $DB->execute($sql, ['status' => $status, 'now' => time(), 'enrolid' => $instance->id, 'userid' => $userid]);
        }

        // Traite le cas où on souhaite juste modifier le rôle.
        if ($roleid !== null) {
            $coursecontext = context_course::instance($instance->courseid);

            $sql = "UPDATE {role_assignments}" .
                " SET roleid = :roleid, timemodified = :now" .
                " WHERE component = 'enrol_select'" .
                " AND userid = :userid" .
                " AND contextid = :contextid" .
                " AND itemid= :itemid";
            $params = [
                'roleid' => $roleid,
                'now' => time(),
                'userid' => $userid,
                'contextid' => $coursecontext->id,
                'itemid' => $instance->id,
            ];
            $DB->execute($sql, $params);
        }
    }

    /**
     * Méthode permettant de déinscrire un utilisateur d'une instance.
     *
     * @param stdClass   $instance      Objet de l'instance de la méthode d'inscription.
     * @param int|string $userid        Identifiant d'un utilisateur.
     *
     * @return void.
     */
    public function unenrol_user(stdClass $instance, $userid) {
        global $DB;

        parent::unenrol_user($instance, $userid);

        // Si la remontée de liste est activée.
        if (empty($instance->customchar2) === false) {
            $this->refill_main_list($instance, $userid);
        }

        // Si les paiements à l'inscription sont activées.
        $instance->customdec1 = intval($instance->customdec1);
        if (empty($instance->customdec1) === false) {
            // On supprime la tâche adhoc associée à l'utilisateur.
            $classname = '\enrol_select\task\check_enrolment_payment';
            $params = ['component' => 'enrol_select', 'classname' => $classname, 'userid' => $userid];
            $tasks = $DB->get_records('task_adhoc', $params);
            foreach ($tasks as $taskid => $task) {
                $customdata = json_decode($task->customdata);
                if (isset($customdata->enrolid) === false) {
                    continue;
                }

                if ($customdata->enrolid !== $instance->id) {
                    continue;
                }

                $DB->delete_records('task_adhoc', ['id' => $taskid]);
            }
        }
    }

    /**
     * Réorganise la liste d'inscription principale.
     *
     * @param stdClass   $instance      Objet de l'instance de la méthode d'inscription.
     * @param int|string $userid        Identifiant d'un utilisateur.
     *
     * @return void.
     */
    public function refill_main_list(stdClass $instance, $userid) {
        global $DB, $USER;

        if ($this->is_active_enrolment_period($instance) === false) {
            // On ne réalimente pas la liste principale si les inscriptions sont closes.
            return;
        }

        if ($USER->id !== $userid) {
            // L'utilisateur courant n'est pas l'utilisateur à désinscrire.
            // Il s'agit probablement d'un enseignant dans la partie management.
            // On ne provoque pas la réalimentation de la liste principale.
            return;
        }

        if (empty($instance->customint3) === true) {
            // Les quotas ne sont pas activés pour ce cours.
            return;
        }

        $sql = "SELECT COUNT(*) FROM {user_enrolments} WHERE enrolid = :enrolid AND status IN (:main, :accepted)";
        $params = ['enrolid' => $instance->id, 'main' => self::MAIN, 'accepted' => self::ACCEPTED];
        $countmain = $DB->count_records_sql($sql, $params);
        if ($countmain >= $instance->customint1) {
            // La liste principale (et des acceptés) est déjà pleine.
            return;
        }

        $countwait = $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'status' => self::WAIT]);
        if ($countwait === 0) {
            // La liste complémentaire est vide.
            return;
        }

        // Détermine le nombre d'inscription à transférer de la liste complémentaire à la liste principale.
        $promote = $instance->customint1 - $countmain;

        // Récupère les utilisateurs sur liste complémentaire par ordre d'inscription.
        $params = ['enrolid' => $instance->id, 'status' => self::WAIT];
        $waitingusers = $DB->get_records('user_enrolments', $params, $sort = 'timecreated ASC');

        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        foreach ($waitingusers as $user) {
            $user->status = self::MAIN;
            $DB->update_record('user_enrolments', $user);

            // Notifie l'utilisateur sur liste d'attente qui vient d'être basculé sur liste principale.
            $eventdata = new \core\message\message();
            $eventdata->courseid = $course->id;
            $eventdata->component = 'enrol_select';
            $eventdata->name = 'select_notification';
            $eventdata->userfrom = core_user::get_noreply_user();
            $eventdata->userto = $DB->get_record('user', ['id' => $user->userid]);
            $eventdata->subject = get_string('enrolcoursesubject', 'enrol_select', $course);
            $eventdata->fullmessage = get_string(
                'message_greetings',
                'enrol_select',
                get_string_on_list_x(self::ACCEPTED, 'message_promote', 'listname')
            );
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = '';
            $eventdata->smallmessage = '';
            $eventdata->notification = 1;

            message_send($eventdata);

            $promote--;
            if ($promote === 0) {
                break;
            }
        }
    }
}
