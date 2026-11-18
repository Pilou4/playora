// Page « Préparez-vous » d'un duel solo : « Jouer maintenant » crée le salon ou prend la place libre par l'API,
// puis ouvre la table. Le serveur vérifie tout (place encore libre, un seul salon à la fois…).
export default class PrepareDuel {
    static init(root = document) {
        root.querySelectorAll('.prepare').forEach((element) => new PrepareDuel(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.csrf = dataset.csrf;
        this.room = dataset.room;
        this.position = dataset.position;
        this.players = Number(dataset.players);
        this.button = element.querySelector('.prepare__play');
        this.error = element.querySelector('.prepare__error');

        this.onPlay = this.onPlay.bind(this);
    }

    start() {
        this.button?.addEventListener('click', this.onPlay);
    }

    async onPlay() {
        this.button.disabled = true;
        this.showError('');

        // Rejoindre : la place libre ; créer : un salon d'une manche avec le nombre de joueurs choisi
        const [url, body] = this.room
            ? [`${this.apiUrl}/${this.room}/places/${this.position}`, null]
            : [this.apiUrl, { targetPoints: 1, announcements: false, players: this.players }];

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: body === null ? null : JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));
            const playUrl = (response.ok ? payload : payload.state)?.mine?.playUrl;

            if (response.ok && playUrl) {
                window.location.assign(playUrl);

                return;
            }

            this.showError(payload.detail ?? 'La partie n’a pas pu commencer : réessayez.');
        } catch {
            this.showError('Connexion perdue : réessayez dans un instant.');
        }

        this.button.disabled = false;
    }

    showError(message) {
        this.error.textContent = message;
        this.error.classList.toggle('prepare__error--visible', message !== '');
    }
}
