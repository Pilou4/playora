// Scripts d'une page de l'admin, chargés seulement si la page en a besoin.
// Appelé au chargement, puis sur le nouveau contenu à chaque changement de page sans rechargement (AdminNavigation.js).
export default class AdminPage {
    static start(root) {
        if (root.querySelector('.admin-form--ajax')) {
            import('./AjaxForm.js').then(({ default: AjaxForm }) => AjaxForm.init(root));
        }

        if (root.querySelector('.admin-settings')) {
            import('./SettingsPreview.js').then(({ default: SettingsPreview }) => SettingsPreview.init(root));
        }

        if (root.querySelector('.admin-active')) {
            import('./ActiveToggle.js').then(({ default: ActiveToggle }) => ActiveToggle.init(root));
        }

        if (root.querySelector('.admin-sortable')) {
            import('./TableSort.js').then(({ default: TableSort }) => TableSort.init(root));
        }
    }
}
