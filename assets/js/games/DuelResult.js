// Résultat d'un duel solo : tant que quelqu'un joue encore (ou qu'une place attend un joueur), le classement
// est relu de temps en temps et redessiné à l'identique (les lignes gardent leur hauteur : rien ne saute).
const REFRESH_DELAY = 5000;
const formatScore = (value) => new Intl.NumberFormat('fr-FR').format(value);

export default class DuelResult {
    static init(root = document) {
        root.querySelectorAll('.duel-result').forEach((element) => new DuelResult(element).start());
    }

    constructor(element) {
        this.element = element;
        this.apiUrl = element.dataset.apiUrl;
        this.state = JSON.parse(element.dataset.state);
        this.list = element.querySelector('.duel-result__ranking');
        this.status = element.querySelector('.duel-result__status');
        this.timer = null;
        this.refresh = this.refresh.bind(this);
    }

    start() {
        this.plan();
    }

    plan() {
        window.clearTimeout(this.timer);

        if (this.state.waiting) {
            this.timer = window.setTimeout(this.refresh, REFRESH_DELAY);
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                this.state = (await response.json()).duel;
                this.render();
            }
        } catch {
            // Réseau coupé : on réessaie au prochain passage
        }

        this.plan();
    }

    render() {
        const { ranking, status, mineFinished } = this.state;
        const best = ranking.find((player) => player.winner);

        if (mineFinished) {
            if (status === 'termine') {
                this.status.textContent = best ? (best.me ? 'Vous avez gagné le duel !' : `${best.pseudo} gagne le duel.`) : 'Égalité !';
            } else {
                this.status.textContent = 'Le duel continue : le classement se met à jour tout seul.';
            }
        }

        this.list.replaceChildren(...ranking.map((player) => this.row(player)));
    }

    row(player) {
        const item = document.createElement('li');
        const rank = document.createElement('span');
        const pseudo = document.createElement('span');
        const score = document.createElement('span');

        item.className = 'duel-result__player';
        item.classList.toggle('duel-result__player--me', player.me);
        item.classList.toggle('duel-result__player--winner', player.winner);
        item.classList.toggle('duel-result__player--free', player.state === 'libre');

        rank.className = 'duel-result__rank';
        rank.classList.toggle('duel-result__rank--none', player.rank === null);
        rank.textContent = player.rank === null ? '–' : String(player.rank);

        pseudo.className = 'duel-result__pseudo';
        pseudo.textContent = player.pseudo;

        if (player.me) {
            const you = document.createElement('span');
            you.className = 'duel-result__you';
            you.textContent = '(vous)';
            pseudo.append(' ', you);
        }

        score.className = 'duel-result__score';

        if (player.score !== null) {
            score.textContent = `${formatScore(player.score)} points`;
        } else if (player.state === 'en-cours') {
            score.append(DuelResult.playing());
        } else {
            score.textContent = player.state === 'libre' ? 'en attente d’un joueur' : 'a fini';
        }

        item.append(rank, pseudo, score);

        return item;
    }

    // « joue » et trois petits points qui montent et descendent l'un après l'autre
    static playing() {
        const playing = document.createElement('span');
        const dots = document.createElement('span');

        playing.className = 'duel-result__playing';
        playing.textContent = 'joue';
        dots.className = 'duel-result__dots';
        dots.setAttribute('aria-hidden', 'true');

        for (let i = 0; i < 3; i += 1) {
            const dot = document.createElement('span');
            dot.className = 'duel-result__dot';
            dots.append(dot);
        }

        playing.append(dots);

        return playing;
    }
}
