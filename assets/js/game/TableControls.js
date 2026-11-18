// Commun à toutes les tables de jeu (belote, tarot, solitaire) : message bref en haut du tapis,
// fenêtre « Quitter la partie ? » et plein écran.

// Message bref (« Personne ne prend : on redistribue »), qui disparaît tout seul
export class Toast {
    constructor(element) {
        this.element = element;
        this.timer = null;
    }

    show(text, duration = 1800) {
        this.element.textContent = text;
        this.element.classList.add('belote__toast--visible');
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.element.classList.remove('belote__toast--visible'), duration);
    }
}

// Fenêtre de confirmation avant de quitter une partie en cours ; l'erreur éventuelle s'affiche dans la fenêtre
export class LeaveDialog {
    // replace : la page de la partie quitte l'historique (« précédent » n'y ramène pas)
    constructor({ dialog, leaveUrl, lobbyUrl, csrf, replace = false }) {
        this.dialog = dialog;
        this.replace = replace;
        this.error = dialog.querySelector('.belote__leave-error');
        this.leaveUrl = leaveUrl;
        this.lobbyUrl = lobbyUrl;
        this.csrf = csrf;
    }

    // Partie terminée : on retourne simplement aux salons ; sinon on demande confirmation
    open(finished) {
        if (finished) {
            this.go();

            return;
        }

        this.error.textContent = '';
        this.dialog.showModal();
    }

    close() {
        this.dialog.close();
    }

    go() {
        if (this.replace) {
            window.location.replace(this.lobbyUrl);
        } else {
            window.location.assign(this.lobbyUrl);
        }
    }

    async confirm(button) {
        button.disabled = true;

        try {
            const response = await fetch(this.leaveUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.csrf },
            });

            if (response.ok) {
                this.go();

                return;
            }

            const body = await response.json().catch(() => ({}));
            this.error.textContent = response.status === 409 && body.detail ? body.detail : 'Impossible de quitter la partie pour le moment. Réessayez.';
        } catch {
            this.error.textContent = 'Connexion perdue. Vérifiez votre réseau et réessayez.';
        }

        button.disabled = false;
    }
}

// « Précédent » du navigateur sur une partie : la partie est quittée aussitôt, comme avec « Quitter » (sa partie
// s'arrête avec ses points, ou un robot prend sa place), et on ne peut plus y revenir avec « Suivant ».
// L'étape d'historique est posée au premier geste du joueur (sans geste, les navigateurs la sautent).
export class BackGuard {
    constructor({ leaveUrl, exitUrl, csrf, isOver }) {
        this.leaveUrl = leaveUrl;
        this.exitUrl = exitUrl;
        this.csrf = csrf;
        this.isOver = isOver;
        this.armed = false;
        this.leaving = false;

        this.arm = this.arm.bind(this);
        this.onPopState = this.onPopState.bind(this);
        this.onPageShow = this.onPageShow.bind(this);
    }

    start(element) {
        element.addEventListener('pointerdown', this.arm);
        element.addEventListener('keydown', this.arm);
        window.addEventListener('popstate', this.onPopState);
        window.addEventListener('pageshow', this.onPageShow);
    }

    arm() {
        if (!this.armed) {
            this.armed = true;
            window.history.pushState({ table: true }, '', window.location.href);
        }
    }

    async onPopState() {
        if (this.leaving) {
            return;
        }

        this.leaving = true;

        if (!this.isOver()) {
            try {
                await fetch(this.leaveUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': this.csrf }, keepalive: true });
            } catch {
                // Réseau coupé : la page suivante le dira ; le temps écoulé fermera la partie
            }
        }

        // La page de la partie quitte l'historique : « Suivant » n'y ramène pas
        window.location.replace(this.exitUrl);
    }

    // Page rendue par le cache du navigateur (« Suivant ») : on la recharge, le serveur dit où l'on en est
    onPageShow(event) {
        if (event.persisted) {
            window.location.reload();
        }
    }
}

// Plein écran sur le plateau seul (et retour)
export function toggleFullscreen(frame) {
    if (document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
    } else {
        frame.requestFullscreen?.().catch(() => {});
    }
}
