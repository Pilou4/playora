// Formulaires de l'admin envoyés sans recharger la page : le serveur répond en JSON, le message s'affiche en haut
import AdminFlash from './AdminFlash.js';

export default class AjaxForm {
    // root : la page, ou le nouveau contenu après un changement de page sans rechargement
    static init(root = document) {
        root.querySelectorAll('.admin-form--ajax').forEach((form) => new AjaxForm(form).start());
    }

    constructor(form) {
        this.form = form;
        this.onSubmit = this.onSubmit.bind(this);
    }

    start() {
        this.form.addEventListener('submit', this.onSubmit);
    }

    async onSubmit(event) {
        event.preventDefault();
        const button = event.submitter;

        if (button) {
            button.disabled = true;
        }

        try {
            const response = await fetch(this.form.action || window.location.href, {
                method: 'POST',
                body: new FormData(this.form),
                headers: { Accept: 'application/json' },
            });
            const body = await response.json().catch(() => ({}));

            AdminFlash.show(body.message ?? (response.ok ? 'Modifications enregistrées.' : 'Les modifications n’ont pas été enregistrées.'), response.ok ? 'success' : 'error');
        } catch {
            AdminFlash.show('Connexion perdue : les modifications n’ont pas été enregistrées. Réessayez.', 'error');
        }

        if (button) {
            button.disabled = false;
        }
    }
}
