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

defined('MOODLE_INTERNAL') || die;

use html_writer;

require_once($CFG->libdir . '/formslib.php');

/**
 * Définition du formulaire pour s'inscire à un cours.
 *
 * @package    enrol_select
 * @copyright  2016 Université Rennes 2
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_select_form extends moodleform {
    /**
     * Définit les champs du formulaire.
     *
     * @return void
     */
    protected function definition() {
        global $CFG, $DB;

        $mform = $this->_form;
        [$instance, $federationrequirement, $roles, $finalform] = $this->_customdata;

        $title = html_writer::tag('div', html_writer::tag('h5', 'Inscription'), ['class' => 'enrol-popup-header']);
        $mform->addElement('html', $title);

        // Course field.
        $attr = ['name' => 'fakefullname', 'class' => 'apsolu-custom-field' ];
        $fullname = html_writer::tag('div', $instance->fullname, $attr);
        $fullnamearr[] = &$mform->createElement('html', $fullname);
        $mform->addGroup($fullnamearr, 'fullnamearr', get_string('course'), [' '], false, ['class' => 'form-readonly mb-4']);

        // Location field.
        if (isset($instance->location)) {
            $attr = ['name' => 'fakelocation'];
            if (isset($instance->site)) {
                $location = html_writer::tag(
                    'div',
                    html_writer::tag('strong', $instance->site) . ' - ' . $instance->location,
                    $attr
                );
                $locationlabel = get_string('site_and_location', 'local_apsolu');
            } else {
                $location = html_writer::tag('div', $instance->location, $attr);
                $locationlabel = get_string('location', 'local_apsolu');
            }

            $locationarr[] = &$mform->createElement('html', $location);

            $mform->addGroup($locationarr, 'locationarr', $locationlabel, [' '], false, ['class' => 'form-readonly mb-4']);
        }

        // Roles field.
        $attr = '';
        if (!$finalform) {
            $attr = ['class' => 'frozen']; // Champ rôle frozen s'il s'agit du formulaire intermédiaire (utilisateur déjà inscrit).
            $fakerole = true;
        } else if (count($roles) === 1) {
            $attr = ['disabled']; // Champ rôle disabled s'il n'y a qu'un rôle possible.
        }
        $mform->addElement('select', 'role', get_string('role', 'local_apsolu'), $roles, $attr);
        $mform->setType('role', PARAM_INT);
        $role = empty($instance->role) ? array_key_first($roles) : $instance->role;
        $mform->setDefault('role', $role);

        if (isset($fakerole)) {
            // Le rôle est affiché sous forme de texte.
            $mform->freeze('role');
        }

        // Nouvelle inscription ou mode modification d'inscription.
        if ($finalform) {
            // Federations field.
            if ($federationrequirement !== APSOLU_FEDERATION_REQUIREMENT_FALSE) {
                $isrequired = $federationrequirement === APSOLU_FEDERATION_REQUIREMENT_TRUE;
                $mform->addElement('checkbox', 'federation', get_string(
                    $isrequired ? 'federation_required' : 'federation_optional',
                    'enrol_select'
                ));
                $mform->setType('federation', PARAM_INT);

                $mform->addHelpButton('federation', $isrequired ? 'federation_required' : 'federation_optional', 'enrol_select');
                $mform->setDefault('federation', 0);
            }

            // Acceptation des recommandations médicales.
            $policy = empty($instance->showpolicy) === false &&
                (empty($CFG->sitepolicy) === false || is_file($CFG->dirroot . '/policy.html') === true);
            if ($policy) {
                $url = $CFG->sitepolicy;
                if (empty($url) === true) {
                    $url = $CFG->wwwroot . '/policy.html';
                }
                $mform->addElement('advcheckbox', 'policy', get_string('policyagree', 'enrol_select', $url));
                $mform->setType('policy', PARAM_INT);
                $mform->setDefault('policy', 0);
            }
        }

        // Submit buttons.
        // Bouton "Enregistrer" ou "S'inscrire" (en mode modification de l'inscription ou nouvelle inscription).
        if ($finalform) {
            $submitstr = empty($instance->role) == false ? get_string('save', 'admin') : get_string('enrol', 'enrol_select');
            $buttonarray[] = &$mform->createElement('submit', 'enrolbutton', $submitstr);
        } else {
            // Bouton 'modifier l'inscription' et 'se désinscrire' (utilisateur déjà inscrit).
            // Si plusieurs rôles autorisés dans ce cours : possibilité de modifier son inscription.
            if (count($roles) > 1) {
                $label = get_string('edit_enrol', 'enrol_select');
                $buttonarray[] = &$mform->createElement('submit', 'editenrol', $label);
            }

            $label = get_string('unenrol', 'enrol_select');
            $buttonarray[] = &$mform->createElement('submit', 'unenrolbutton', $label);
        }

        // Bouton 'Annuler'.
        $attributes = new stdClass();
        $attributes->href = $CFG->wwwroot . '/enrol/select/overview.php';
        $attributes->class = 'btn btn-default btn-secondary apsolu-cancel-a';
        $buttonarray[] = &$mform->createElement('static', '', '', get_string('cancel_link', 'local_apsolu', $attributes));

        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);

        // Hidden fields.
        $mform->addElement('hidden', 'enrolid', $instance->enrolid);
        $mform->setType('enrolid', PARAM_INT);

        $mform->addElement('hidden', 'fullname', $instance->fullname);
        $mform->setType('fullname', PARAM_TEXT);
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     *
     * @return array The errors that were found.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        [$instance, $federationrequirement] = $this->_customdata;

        // Vérifications ignorées lors de la désinscription.
        if ($data['unenrolbutton'] == false) {
            // En cas de modification de l'inscription : forcer la valeur du rôle à changer. Cela permet d'éviter
            // les messages d'erreur pour les inscriptions réalisées sur un rôle dont le quota est dépassé.
            if (empty($instance->role) == false && $data['role'] == $instance->role) {
                $errors['role'] = get_string('error_unchanged_role', 'enrol_select');
            }

            // L'acceptation des recommandations médicales est nécessaire ?
            if (isset($data['policy']) && $data['policy'] == 0) {
                $errors['policy'] = get_string('required');
            }

            // L'adhésion à la fédération est obligatoire ?
            if ($federationrequirement === APSOLU_FEDERATION_REQUIREMENT_TRUE && $data['federation'] == 0) {
                $errors['federation'] = get_string('required');
            }
        }

        return $errors;
    }
}
