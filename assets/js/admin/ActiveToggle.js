// Jeu en ligne / hors ligne : le bouton envoie le changement sans recharger la page ;
// la pastille d'état, le bouton et la pastille « inactif » du menu de gauche suivent aussitôt.
import AdminFlash from './AdminFlash.js';

export default class ActiveToggle {
    static init(root = document) {
        root.querySelectorAll('.admin-active').forEach((form) => new ActiveToggle(form).start());
    }

    constructor(form) {
        this.form = form;
        this.value = form.querySelector('.admin-active__value');
        this.chip = form.querySelector('.admin-active__chip');
        this.state = form.querySelector('.admin-active__state');
        this.button = form.querySelector('.admin-active__button');
        this.onSubmit = this.onSubmit.bind(this);
    }

    start() {
        this.form.addEventListener('submit', this.onSubmit);
    }

    async onSubmit(event) {
        event.preventDefault();

        if (this.button.disabled) {
            return;
        }

        const data = new FormData(this.form);
        this.button.disabled = true;

        try {
            const response = await fetch(this.form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const body = await response.json().catch(() => ({}));

            if (response.ok) {
                this.show(body.active === true);
                AdminFlash.show(body.message ?? 'Enregistré.', 'success');
            } else {
                AdminFlash.show(body.message ?? 'Le changement n’a pas été enregistré.', 'error');
            }
        } catch {
            AdminFlash.show('Connexion perdue : le changement n’a pas été enregistré. Réessayez.', 'error');
        }

        this.button.disabled = false;
        this.button.focus();
    }

    show(active) {
        this.form.classList.toggle('admin-active--off', !active);
        this.value.value = active ? '0' : '1';
        this.state.textContent = active ? 'En ligne' : 'Hors ligne';
        this.chip.title = active ? 'Visible dans la liste des jeux' : 'Caché : seuls les admins y jouent';
        this.button.textContent = active ? 'Mettre hors ligne' : 'Mettre en ligne';

        document.querySelectorAll('.admin__nav-game').forEach((game) => {
            if (game.dataset.game === this.form.dataset.game) {
                game.querySelector('.admin__nav-badge')?.classList.toggle('admin__nav-badge--hidden', active);
            }
        });
    }
}
