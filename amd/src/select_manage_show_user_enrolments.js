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

/**
 * Module gérant l'affichage d'une modale récapitulant les inscriptions d'un utilisateur inscrit,
 * depuis la page de gestion des inscriptions dans un cours.
 *
 * @module     enrol_select/select_manage_show_user_enrolments
 * @copyright  2026 Université Rennes 2
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/modal', 'core/ajax', 'core/templates', 'core/notification'],
    function(Modal, Ajax, Templates, Notification) {

        // Contenu mis en cache (userid => Promise).
        const cache = new Map();

        // Appel Ajax pour récupérer le contenu propre à cet utilisateur.
        const getDetails = (userid, courseid) => {
            if (!cache.has(userid)) {
                const promise = Ajax.call([{
                    methodname: 'enrol_select_get_user_enrolments_summary',
                    args: {userid: userid, courseid: courseid},
                }])[0].catch((error) => {
                    // Les requêtes échouées ne sont pas mises en cache.
                    cache.delete(userid);
                    throw error;
                });
                cache.set(userid, promise);
            }
            return cache.get(userid);
        };

        return {
            initialise: async function(courseid) {
                // Afficher la modale au clic sur le bouton (utilise la délégation d'événements).
                document.addEventListener('click', async(e) => {
                    const trigger = e.target.closest('[data-action="enrol_select/show-user-enrolments"]');
                    if (!trigger) {
                        return;
                    }
                    e.preventDefault();

                    try {
                        const data = await getDetails(parseInt(trigger.dataset.userid, 10), courseid);
                        const modal = await Modal.create({
                            title: trigger.dataset.title,
                            body: Templates.render('enrol_select/show_user_enrolments', data),
                            large: true,
                            show: true,
                            removeOnClose: true,
                        });
                        const root = modal.getRoot()[0];
                        root.classList.add('enrol-select-manage');
                        root.classList.add('user-enrolments-details');
                        root.querySelector('.modal-header').classList.add('text-bg-primary');
                        root.querySelector('.modal-header .btn-close').classList.add('btn-close-white');
                    } catch (error) {
                        Notification.exception(error);
                    }
                });
            }
        };
    }
);
