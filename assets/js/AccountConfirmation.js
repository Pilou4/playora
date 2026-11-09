// La page d'attente passe d'elle-même à la page « merci » quand l'adresse est confirmée :
// tout de suite si le lien est ouvert dans ce navigateur, sinon au prochain contrôle.
const CHANNEL = 'playora-compte';
const POLL_DELAY = 5000;
const MAX_WAIT = 60 * 60 * 1000;

export default class AccountConfirmation {
    static init(root = document) {
        root.querySelectorAll('.auth__panel--waiting').forEach((panel) => new AccountConfirmation(panel).watch());

        if (root.querySelector('.auth__panel--confirmed') && 'BroadcastChannel' in window) {
            new BroadcastChannel(CHANNEL).postMessage('confirmed');
        }
    }

    constructor(panel) {
        this.statusUrl = panel.dataset.statusUrl;
        this.confirmedUrl = panel.dataset.confirmedUrl;
        this.startedAt = Date.now();
        this.timer = null;

        this.check = this.check.bind(this);
        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        this.goToConfirmed = this.goToConfirmed.bind(this);
    }

    watch() {
        if ('BroadcastChannel' in window) {
            new BroadcastChannel(CHANNEL).addEventListener('message', this.goToConfirmed);
        }

        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.schedule();
    }

    schedule() {
        window.clearTimeout(this.timer);

        if (Date.now() - this.startedAt < MAX_WAIT) {
            this.timer = window.setTimeout(this.check, POLL_DELAY);
        }
    }

    onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            this.check();
        } else {
            window.clearTimeout(this.timer);
        }
    }

    async check() {
        try {
            const response = await fetch(this.statusUrl, { headers: { Accept: 'application/json' } });
            const { confirmed } = await response.json();

            if (confirmed) {
                this.goToConfirmed();

                return;
            }
        } catch {
            // Réseau coupé : on réessaie au prochain tour
        }

        if (document.visibilityState === 'visible') {
            this.schedule();
        }
    }

    goToConfirmed() {
        window.location.assign(this.confirmedUrl);
    }
}
