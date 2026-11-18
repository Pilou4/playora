// Table du 8 américain (disposition A). Le serveur décide de tout : la page envoie « je pose cette carte »,
// « je pioche », « je passe », puis affiche la vue renvoyée par le serveur, qui fait foi ; la liste des cartes
// jouables vient aussi du serveur. Les cartes glissent de leur ancienne place à la nouvelle (rien ne saute).

import { BackGuard, LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';
import Sound, { SoundButton } from '../Sound.js';

const SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const SUIT_SIGNS = { pique: '♠', coeur: '♥', carreau: '♦', trefle: '♣' };
const RANKS = ['as', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'valet', 'dame', 'roi'];
const RANK_LABELS = { as: 'As', valet: 'Valet', dame: 'Dame', roi: 'Roi' };
// Places des adversaires autour de la table, selon leur nombre (du joueur suivant, à gauche, jusqu'au précédent, à droite)
const SEATS = {
    1: ['top'],
    2: ['top-left', 'top-right'],
    3: ['left', 'top', 'right'],
    4: ['left', 'top-left', 'top-right', 'right'],
    5: ['left', 'top-left', 'top', 'top-right', 'right'],
};
// Les cartes de la pile, légèrement de travers
const PILE_ANGLES = [-12, 7, -3];
const MAX_BACKS = 10;
const MOVE_DURATION = 260;
const RESULTS_DURATION = 4500;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

// Main triée : par couleur, puis par hauteur
const sortHand = (codes) => [...codes].sort((a, b) => {
    const [sa, ra] = a.split('-');
    const [sb, rb] = b.split('-');

    return SUITS.indexOf(sa) - SUITS.indexOf(sb) || RANKS.indexOf(ra) - RANKS.indexOf(rb) || a.localeCompare(b);
});

export default class HuitTable {
    static init(root = document) {
        root.querySelectorAll('.huit').forEach((element) => new HuitTable(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.cardsUrl = dataset.cardsUrl;
        this.backUrl = dataset.backUrl;
        this.view = JSON.parse(dataset.view);

        this.felt = element.querySelector('.rami__felt');
        this.opponents = element.querySelector('.rami__opponents');
        this.stock = element.querySelector('.huit__stock');
        this.pile = element.querySelector('.huit__pile');
        this.suitLabel = element.querySelector('.huit__suit');
        this.directionLabel = element.querySelector('.huit__direction');
        this.hand = element.querySelector('.rami__hand');
        this.hint = element.querySelector('.rami__hint');
        this.announceButton = element.querySelector('.huit__announce');
        this.passButton = element.querySelector('.huit__pass');
        this.suits = element.querySelector('.huit__suits');
        this.clockFill = element.querySelector('.rami__clock-fill');
        this.scoreValue = element.querySelector('.rami__score-value');
        this.roundValue = element.querySelector('.rami__round');
        this.overlay = element.querySelector('.rami__overlay');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.rami__leave-dialog'), leaveUrl: dataset.leaveUrl, lobbyUrl: dataset.lobbyUrl, csrf: this.csrf });

        // « Carte ! » annoncé pour la prochaine carte posée ; 8 en attente du choix de la couleur
        this.announce = false;
        this.eight = null;
        this.busy = false;
        // Dernière version annoncée par Mercure, et une mise à jour en cours : aucune annonce n'est perdue
        this.latestVersion = 0;
        this.fetching = false;
        this.deadline = 0;
        this.timeoutSent = false;
        this.clockTimer = null;
        this.resultsTimer = null;
        this.shownRound = this.view.lastRound?.number ?? 0;

        this.onClick = this.onClick.bind(this);
        this.onResize = this.onResize.bind(this);
        this.onUpdate = this.onUpdate.bind(this);
        this.tick = this.tick.bind(this);
        this.hideResults = this.hideResults.bind(this);
    }

    start() {
        // « Précédent » du navigateur : la partie est quittée (un robot prend la place), sans retour possible
        new BackGuard({ leaveUrl: this.element.dataset.leaveUrl, exitUrl: this.element.dataset.lobbyUrl, csrf: this.csrf, isOver: () => this.view.phase === 'terminee' }).start(this.element);
        // Bouton du son : coupé ou remis, gardé dans ce navigateur
        SoundButton.start(this.element);
        this.element.addEventListener('click', this.onClick);
        window.addEventListener('resize', this.onResize);
        this.onResize();
        this.render(this.view, false);

        if (this.hubUrl && 'EventSource' in window) {
            new EventSource(this.hubUrl).addEventListener('message', this.onUpdate);
        }
    }

    // La table occupe tout l'écran sous l'en-tête du site
    onResize() {
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        this.element.style.setProperty('--offset', `${Math.round(top)}px`);
    }

    // ---------- État ----------

    get myTurn() {
        return this.view.phase === 'jeu' && this.view.turn === this.view.me;
    }

    player(position) {
        return this.view.players.find((player) => player.position === position);
    }

    // ---------- Affichage ----------

    // Affiche la vue ; une annonce arrivée entre-temps (pendant un coup du joueur) est rattrapée aussitôt
    render(view, animate = true) {
        this.paint(view, animate);
        this.catchUp();
    }

    paint(view, animate = true) {
        const before = animate ? this.positions() : new Map();

        // Nouvelle vue du serveur : le temps du tour repart d'elle
        if (view !== this.view || this.deadline === 0) {
            this.deadline = performance.now() + view.secondsLeft * 1000;
            this.timeoutSent = false;
        }

        // Nouvelle donne (nouvelle manche) : le bruit des cartes distribuées
        if (animate && this.view && view.round !== this.view.round) {
            Sound.deal(view.hand.length * view.players.length);
        }

        this.view = view;

        if (!this.myTurn) {
            this.announce = false;
            this.eight = null;
        }

        this.roundValue.textContent = String(view.round);
        this.scoreValue.textContent = String(this.player(view.me)?.score ?? 0);
        this.renderOpponents();
        this.renderCenter();
        this.renderHand();
        this.renderActions();
        this.renderResults();
        this.tick();

        if (animate) {
            this.slide(before);
        }
    }

    positions() {
        const positions = new Map();

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => positions.set(card.dataset.card, card.getBoundingClientRect()));

        return positions;
    }

    // Chaque carte glisse de son ancienne place à la nouvelle ; une carte nouvelle apparaît en douceur
    slide(before) {
        if (REDUCED.matches) {
            return;
        }

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => {
            const from = before.get(card.dataset.card);

            if (!from) {
                card.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });

                return;
            }

            const to = card.getBoundingClientRect();
            const dx = from.left - to.left;
            const dy = from.top - to.top;

            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                card.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }],
                    { duration: MOVE_DURATION, easing: 'cubic-bezier(.2, .8, .2, 1)' },
                );
            }
        });
    }

    // Une carte (null : un dos)
    cardElement(code, tag = 'span') {
        const card = document.createElement(tag);
        const image = document.createElement('img');

        card.className = 'card rami__card';
        image.className = 'card__face';
        image.draggable = false;

        if (code === null) {
            card.classList.add('card--back');
            image.src = this.backUrl;
            image.alt = '';
        } else {
            const [suit, rank] = code.split('-');
            card.dataset.card = code;
            image.src = `${this.cardsUrl}${suit}/${rank}.svg`;
            image.alt = this.label(code);
        }

        card.append(image);

        return card;
    }

    label(code) {
        const [suit, rank] = code.split('-');

        return `${RANK_LABELS[rank] ?? rank} de ${SUIT_LABELS[suit]}`;
    }

    // Les autres joueurs autour de la table : leurs cartes de dos, leur nom, leur nombre de cartes et leurs points
    renderOpponents() {
        const { players, me, turn } = this.view;
        const count = players.length;
        const others = [];

        for (let step = 1; step < count; step += 1) {
            others.push(players.find((player) => player.position === (me + step) % count));
        }

        this.opponents.replaceChildren(...others.map((player, index) => {
            const element = document.createElement('div');
            const fan = document.createElement('div');
            const tag = document.createElement('div');
            const avatar = document.createElement('span');
            const name = document.createElement('p');
            const info = document.createElement('p');
            const backs = Math.min(player.cards, MAX_BACKS);

            element.className = `huit__opponent huit__opponent--${SEATS[others.length][index]}${player.position === turn && this.view.phase === 'jeu' ? ' huit__opponent--turn' : ''}`;
            fan.className = 'huit__fan';
            fan.style.setProperty('--n', String(backs));
            fan.append(...Array.from({ length: backs }, (_, i) => {
                const back = this.cardElement(null);
                back.style.setProperty('--i', String(i));

                return back;
            }));

            tag.className = 'rami__opponent';
            avatar.className = 'rami__avatar';

            if (player.avatar) {
                const image = document.createElement('img');
                image.src = player.avatar;
                image.alt = '';
                image.width = 40;
                image.height = 40;
                avatar.append(image);
            } else {
                avatar.textContent = player.pseudo.charAt(0).toUpperCase();
            }

            name.className = 'rami__opponent-name';
            name.textContent = player.pseudo;
            info.className = 'rami__opponent-info';
            info.textContent = `${player.cards} carte${player.cards > 1 ? 's' : ''} · ${player.score} pts`;
            tag.append(avatar, name, info);
            element.append(fan, tag);

            return element;
        }));
    }

    // La pioche, les dernières cartes de la pile, la couleur demandée et le sens du jeu
    renderCenter() {
        const { stock, pile, suit, direction, penalty } = this.view;
        const canDraw = this.myTurn && !this.view.drawn && !this.busy;

        this.stock.replaceChildren(...(stock > 0 ? [this.cardElement(null)] : []), this.pileLabel(penalty > 0 ? `Piocher ${penalty}` : `Pioche · ${stock}`));
        this.stock.disabled = !canDraw;
        this.stock.classList.toggle('huit__stock--active', canDraw);
        this.stock.setAttribute('aria-label', penalty > 0 ? `Piocher ${penalty} cartes` : `Piocher (${stock} cartes)`);

        this.pile.replaceChildren(...pile.map((code, index) => {
            const card = this.cardElement(code);
            card.classList.add('huit__pile-card');
            card.style.setProperty('--angle', `${PILE_ANGLES[(index + pile.length) % PILE_ANGLES.length]}deg`);

            return card;
        }));

        this.suitLabel.textContent = `Couleur : ${SUIT_SIGNS[suit]} ${SUIT_LABELS[suit]}`;
        this.suitLabel.className = `huit__suit huit__suit--${suit === 'coeur' || suit === 'carreau' ? 'red' : 'black'}`;
        this.directionLabel.textContent = direction === 1 ? '↻ sens des aiguilles' : '↺ sens inverse';
    }

    pileLabel(text) {
        const label = document.createElement('span');
        label.className = 'rami__pile-label';
        label.textContent = text;

        return label;
    }

    // Ma main en éventail : les cartes jouables montent, les autres sont un peu grisées
    renderHand() {
        const cards = sortHand(this.view.hand);
        const playable = new Set(this.myTurn && !this.busy ? this.view.playable : []);

        this.hand.style.setProperty('--n', String(cards.length));
        this.hand.replaceChildren(...cards.map((code, index) => {
            const item = document.createElement('li');
            const card = this.cardElement(code, 'button');

            item.className = 'rami__hand-item';
            item.style.setProperty('--i', String(index));
            card.type = 'button';
            card.classList.add('rami__card--hand');
            card.classList.toggle('huit__card--playable', playable.has(code));
            card.classList.toggle('huit__card--off', this.myTurn && !playable.has(code));
            card.classList.toggle('rami__card--selected', code === this.eight);
            card.disabled = !playable.has(code);
            item.append(card);

            return item;
        }));
    }

    renderActions() {
        const { drawn, penalty, hand } = this.view;

        this.announceButton.hidden = !this.myTurn || hand.length !== 2;
        this.announceButton.classList.toggle('rami__button--brand', this.announce);
        this.announceButton.setAttribute('aria-pressed', String(this.announce));
        this.passButton.hidden = !this.myTurn || !drawn;
        this.passButton.disabled = this.busy;
        this.suits.classList.toggle('huit__suits--visible', this.eight !== null);

        if (this.view.phase !== 'jeu') {
            this.hint.textContent = '';
        } else if (!this.myTurn) {
            this.hint.textContent = `À ${this.player(this.view.turn)?.pseudo ?? 'un autre joueur'} de jouer.`;
        } else if (penalty > 0) {
            this.hint.textContent = `Posez un As, ou piochez ${penalty} cartes.`;
        } else if (drawn) {
            this.hint.textContent = 'Posez la carte piochée, ou passez.';
        } else if (this.eight) {
            this.hint.textContent = 'Choisissez la couleur qui suit.';
        } else {
            this.hint.textContent = this.view.playable.length ? 'À vous : posez une carte.' : 'Rien à poser : piochez une carte.';
        }
    }

    // Fin d'une manche : les points de chacun, quelques secondes ; fin de partie : le classement, jusqu'au retour aux salons
    renderResults() {
        const { lastRound, phase } = this.view;
        const finished = phase === 'terminee';
        const title = this.overlay.querySelector('.rami__panel-title');
        const list = this.overlay.querySelector('.rami__results');
        const link = this.overlay.querySelector('.rami__panel-link');
        const fresh = lastRound && lastRound.number > this.shownRound;

        if (!finished && !fresh) {
            return;
        }

        this.shownRound = lastRound?.number ?? this.shownRound;
        link.classList.toggle('rami__panel-link--visible', finished);

        if (finished) {
            const winner = this.player(this.view.winner);
            title.textContent = this.view.draw ? 'Égalité !' : (this.view.winner === this.view.me ? 'Vous avez gagné !' : `${winner?.pseudo ?? 'Un autre joueur'} gagne la partie`);
        } else {
            title.textContent = `Manche ${lastRound.number} : ${lastRound.winner === this.view.me ? 'vous finissez' : `${this.player(lastRound.winner)?.pseudo} finit`}`;
        }

        list.replaceChildren(...[...this.view.players].sort((a, b) => a.score - b.score).map((player) => {
            const row = document.createElement('li');
            const points = lastRound?.penalties[player.position];

            row.className = 'rami__result';
            row.textContent = `${player.position === this.view.me ? 'Vous' : player.pseudo} : ${player.score} pts${!finished && points !== undefined ? ` (+${points})` : ''}`;

            return row;
        }));

        this.overlay.classList.add('rami__overlay--visible');
        window.clearTimeout(this.resultsTimer);

        if (!finished) {
            this.resultsTimer = window.setTimeout(this.hideResults, RESULTS_DURATION);
        }
    }

    hideResults() {
        this.overlay.classList.remove('rami__overlay--visible');
    }

    // ---------- Temps ----------

    // La barre se vide pendant le tour ; à zéro, n'importe quel joueur demande au serveur de jouer pour le retardataire
    tick() {
        window.clearTimeout(this.clockTimer);

        if (this.view.phase !== 'jeu') {
            this.clockFill.style.setProperty('--left', '0');

            return;
        }

        const left = Math.max(0, (this.deadline - performance.now()) / 1000);
        this.clockFill.style.setProperty('--left', (left / this.view.seconds).toFixed(4));
        this.clockFill.classList.toggle('rami__clock-fill--late', left <= 5);

        if (left <= 0) {
            this.clockTimer = window.setTimeout(() => this.sendTimeout(), this.myTurn ? 1200 : 2500);

            return;
        }

        this.clockTimer = window.setTimeout(this.tick, 250);
    }

    sendTimeout() {
        if (!this.timeoutSent) {
            this.timeoutSent = true;
            this.send('temps-ecoule', {}, true);
        }
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const target = event.target;
        const card = target.closest('.rami__card--hand');
        const suit = target.closest('.huit__suit-choice');

        if (card && !card.disabled) {
            this.playCard(card.dataset.card);
        } else if (suit && this.eight) {
            this.sendCard(this.eight, suit.dataset.suit);
        } else if (target.closest('.huit__stock')) {
            this.send('pioche', {});
        } else if (target.closest('.huit__pass')) {
            this.send('passe', {});
        } else if (target.closest('.huit__announce')) {
            this.announce = !this.announce;
            this.renderActions();
        } else if (target.closest('.belote__tool--rules')) {
            this.element.querySelector('.belote__rules').showModal();
        } else if (target.closest('.belote__rules-close')) {
            this.element.querySelector('.belote__rules').close();
        } else if (target.closest('.belote__quit')) {
            this.leaveDialog.open(this.view.phase === 'terminee');
        } else if (target.closest('.rami__leave-cancel')) {
            this.leaveDialog.close();
        } else if (target.closest('.rami__leave-confirm')) {
            this.leaveDialog.confirm(target.closest('.rami__leave-confirm'));
        } else if (target.closest('.belote__tool--fullscreen')) {
            toggleFullscreen(this.element.querySelector('.belote__frame'));
        } else if (target.closest('.rami__overlay') && this.view.phase !== 'terminee') {
            this.hideResults();
        }
    }

    // Un 8 : on choisit d'abord la couleur (sauf si c'est la dernière carte) ; une autre carte part tout de suite
    playCard(code) {
        if (code.split('-')[1] === '8' && this.view.hand.length > 1) {
            this.eight = this.eight === code ? null : code;
            this.renderHand();
            this.renderActions();

            return;
        }

        this.sendCard(code, null);
    }

    sendCard(code, suit) {
        this.eight = null;
        this.send('cartes', { card: code, suit, announce: this.announce });
    }

    // ---------- Échanges avec le serveur ----------

    async send(action, body, quiet = false) {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.renderHand();

        try {
            const response = await fetch(`${this.apiUrl}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            this.busy = false;

            if (response.ok) {
                this.announce = false;
                this.render(payload.view);

                return;
            }

            // Coup refusé par les règles (409, 422) : le message du serveur, en français, est pour le joueur.
            // Toute autre erreur reste technique : jamais affichée telle quelle, la table se remet à jour.
            const refused = response.status === 409 || response.status === 422;

            if (!quiet) {
                this.toast.show(refused ? payload.detail ?? 'Coup refusé.' : 'Un souci est survenu : la partie reprend.', 2600);
            }

            if (payload.view) {
                this.render(payload.view);
            } else {
                await this.refresh();
            }
        } catch {
            this.busy = false;
            this.toast.show('Connexion perdue : la partie reprend.', 2600);
            await this.refresh();
        }
    }

    async refresh() {
        this.fetching = true;

        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                const { view } = await response.json();
                this.fetching = false;

                if (!this.busy && view.version >= this.view.version) {
                    this.render(view);
                }
            }
        } catch {
            // Réseau coupé : le prochain message relancera la mise à jour
        } finally {
            this.fetching = false;
        }
    }

    // Un autre joueur a joué (Mercure). Pendant un coup du joueur, l'annonce n'est plus ignorée :
    // elle est gardée et la table se met à jour juste après (sinon cet écran prenait du retard sur les autres)
    onUpdate(message) {
        const { version } = JSON.parse(message.data);

        this.latestVersion = Math.max(this.latestVersion, version);
        this.catchUp();
    }

    catchUp() {
        if (!this.busy && !this.fetching && this.latestVersion > this.view.version) {
            this.refresh();
        }
    }
}
