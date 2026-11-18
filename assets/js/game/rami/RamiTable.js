// Table de rami (disposition A). Le serveur décide de tout : la page envoie « je pioche, je pose ces cartes,
// je défausse celle-ci », puis affiche la vue renvoyée par le serveur, qui fait foi. Les cartes glissent
// de leur ancienne place à la nouvelle (rien ne saute).
//
// La main : un clic choisit ou laisse une carte. Avec des cartes choisies :
// - « Grouper » met la combinaison de côté (la première pose peut en compter plusieurs), « Poser » envoie le tout ;
// - un clic sur une combinaison posée la complète avec la carte choisie (ou y reprend le joker qu'elle remplace) ;
// - « Défausser » (ou un clic sur la défausse) jette la carte choisie et termine le tour.

import { BackGuard, LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';
import Sound, { SoundButton } from '../Sound.js';

const SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
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
const MOVE_DURATION = 260;
const RESULTS_DURATION = 4500;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

const isJoker = (code) => code.startsWith('joker-');
const faceOf = (code) => code.split('-').slice(0, 2).join('-');

// Main triée : par couleur, puis par hauteur ; les jokers à la fin
const sortHand = (codes) => [...codes].sort((a, b) => {
    if (isJoker(a) || isJoker(b)) {
        return Number(isJoker(a)) - Number(isJoker(b)) || a.localeCompare(b);
    }

    const [sa, ra] = a.split('-');
    const [sb, rb] = b.split('-');

    return SUITS.indexOf(sa) - SUITS.indexOf(sb) || RANKS.indexOf(ra) - RANKS.indexOf(rb) || a.localeCompare(b);
});

export default class RamiTable {
    static init(root = document) {
        root.querySelectorAll('.rami').forEach((element) => new RamiTable(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.cardsUrl = dataset.cardsUrl;
        this.backUrl = dataset.backUrl;
        this.jokersUrl = dataset.jokersUrl;
        this.view = JSON.parse(dataset.view);

        this.felt = element.querySelector('.rami__felt');
        this.opponents = element.querySelector('.rami__opponents');
        this.melds = element.querySelector('.rami__melds');
        this.stock = element.querySelector('.rami__pile--stock');
        this.discardPile = element.querySelector('.rami__pile--discard');
        this.pending = element.querySelector('.rami__pending');
        this.hand = element.querySelector('.rami__hand');
        this.hint = element.querySelector('.rami__hint');
        this.groupButton = element.querySelector('.rami__button--group');
        this.meldButton = element.querySelector('.rami__button--meld');
        this.discardButton = element.querySelector('.rami__button--discard');
        this.giveBackButton = element.querySelector('.rami__button--give-back');
        this.clockFill = element.querySelector('.rami__clock-fill');
        this.scoreValue = element.querySelector('.rami__score-value');
        this.roundValue = element.querySelector('.rami__round');
        this.overlay = element.querySelector('.rami__overlay');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.rami__leave-dialog'), leaveUrl: dataset.leaveUrl, lobbyUrl: dataset.lobbyUrl, csrf: this.csrf });

        // Cartes choisies dans la main, et combinaisons mises de côté avant de poser
        this.selected = new Set();
        this.groups = [];
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
        return this.view.phase === 'jeu' && this.view.turn === this.view.me && this.view.step !== 'finie';
    }

    get canDraw() {
        return this.myTurn && this.view.step === 'pioche' && !this.busy;
    }

    get canPlay() {
        return this.myTurn && this.view.step === 'jeu' && !this.busy;
    }

    get opened() {
        return this.player(this.view.me)?.opened ?? false;
    }

    player(position) {
        return this.view.players.find((player) => player.position === position);
    }

    // Les cartes de la main qui ne sont pas déjà mises de côté
    freeHand() {
        const grouped = new Set(this.groups.flat());

        return sortHand(this.view.hand.filter((code) => !grouped.has(code)));
    }

    // ---------- Affichage ----------

    // Affiche la vue ; une annonce arrivée entre-temps (pendant un coup du joueur) est rattrapée aussitôt
    render(view, animate = true) {
        this.paint(view, animate);
        this.catchUp();
    }

    paint(view, animate = true) {
        const before = animate ? this.positions() : new Map();

        // Nouvelle vue du serveur : le temps du tour repart d'elle (un simple choix de carte ne le touche pas)
        if (view !== this.view || this.deadline === 0) {
            this.deadline = performance.now() + view.secondsLeft * 1000;
            this.timeoutSent = false;
        }

        // Nouvelle donne (nouvelle manche) : le bruit des cartes distribuées
        if (animate && this.view && view.round !== this.view.round) {
            Sound.deal(view.hand.length * view.players.length);
        }

        this.view = view;
        // Les cartes choisies ou groupées qui ne sont plus dans la main sont oubliées
        const hand = new Set(view.hand);
        this.selected = new Set([...this.selected].filter((code) => hand.has(code)));
        this.groups = this.groups.filter((group) => group.every((code) => hand.has(code)));

        this.renderHeader();
        this.renderOpponents();
        this.renderMelds();
        this.renderPiles();
        this.renderPending();
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
                    [{ transform: `translate(${dx}px, ${dy}px) scale(${from.width / to.width})`, transformOrigin: 'top left' }, { transform: 'none', transformOrigin: 'top left' }],
                    { duration: MOVE_DURATION, easing: 'cubic-bezier(.2, .8, .2, 1)' },
                );
            }
        });
    }

    // Une carte (null : un dos). « face » : la carte que représente un joker posé, pour son libellé
    cardElement(code, tag = 'span', face = null) {
        const card = document.createElement(tag);
        const image = document.createElement('img');

        card.className = 'card rami__card';
        image.className = 'card__face';
        image.draggable = false;

        if (code === null) {
            card.classList.add('card--back');
            image.src = this.backUrl;
            image.alt = '';
        } else if (isJoker(code)) {
            card.dataset.card = code;
            image.src = `${this.jokersUrl}joker-${Number(code.slice(6)) <= 2 ? 'rouge' : 'noir'}.svg`;
            image.alt = face ? `Joker (${this.label(face)})` : 'Joker';
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

    renderHeader() {
        this.roundValue.textContent = String(this.view.round);
        this.scoreValue.textContent = String(this.player(this.view.me)?.score ?? 0);
    }

    // Les autres joueurs autour de la table : nom, nombre de cartes, pénalités ; celui dont c'est le tour est éclairé
    renderOpponents() {
        const { players, me, turn } = this.view;
        const count = players.length;
        const others = [];

        for (let step = 1; step < count; step += 1) {
            others.push(players.find((player) => player.position === (me + step) % count));
        }

        this.opponents.replaceChildren(...others.map((player, index) => {
            const element = document.createElement('div');
            const avatar = document.createElement('span');
            const name = document.createElement('p');
            const info = document.createElement('p');

            element.className = `rami__opponent rami__opponent--${SEATS[others.length][index]}${player.position === turn && this.view.phase === 'jeu' ? ' rami__opponent--turn' : ''}`;
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
            element.append(avatar, name, info);

            return element;
        }));
    }

    // Les combinaisons posées, groupées par joueur (les miennes d'abord). Avec une carte choisie, on peut les compléter.
    renderMelds() {
        const { melds, players, me } = this.view;
        const owners = [me, ...players.map((player) => player.position).filter((position) => position !== me)];
        const target = this.canPlay && this.opened && this.selected.size === 1;

        this.melds.replaceChildren(...owners.filter((owner) => melds.some((meld) => meld.owner === owner)).map((owner) => {
            const group = document.createElement('div');
            const title = document.createElement('p');
            const list = document.createElement('div');

            group.className = 'rami__meld-group';
            title.className = 'rami__meld-owner';
            title.textContent = owner === me ? 'Vous' : this.player(owner)?.pseudo ?? '';
            list.className = 'rami__meld-list';
            list.append(...melds.filter((meld) => meld.owner === owner).map((meld) => {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = `rami__meld${target ? ' rami__meld--target' : ''}`;
                button.dataset.meld = String(meld.index);
                button.disabled = !target;
                button.setAttribute('aria-label', `${meld.kind === 'suite' ? 'Suite' : 'Série'} : ${meld.cards.map((card) => this.label(card.face)).join(', ')}`);
                button.append(...meld.cards.map((card) => this.cardElement(card.code, 'span', isJoker(card.code) ? card.face : null)));

                return button;
            }));
            group.append(title, list);

            return group;
        }));

        if (melds.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'rami__meld-empty';
            empty.textContent = `Aucune combinaison posée. Première pose : ${this.view.firstMeld} points, dont une suite.`;
            this.melds.append(empty);
        }
    }

    renderPiles() {
        const { stock, discard } = this.view;

        this.stock.replaceChildren(...(stock > 0 ? [this.cardElement(null)] : []), this.pileLabel(`Pioche · ${stock}`));
        this.stock.disabled = !this.canDraw;
        this.stock.classList.toggle('rami__pile--active', this.canDraw);

        this.discardPile.replaceChildren(...(discard ? [this.cardElement(discard)] : []), this.pileLabel('Défausse'));
        this.discardPile.classList.toggle('rami__pile--empty', !discard);
        this.discardPile.disabled = !(this.canDraw && discard) && !(this.canPlay && this.selected.size === 1);
        this.discardPile.classList.toggle('rami__pile--active', !this.discardPile.disabled);
        this.discardPile.setAttribute('aria-label', discard ? `Défausse : ${this.label(discard)}` : 'Défausse vide');
    }

    pileLabel(text) {
        const label = document.createElement('span');
        label.className = 'rami__pile-label';
        label.textContent = text;

        return label;
    }

    renderPending() {
        this.pending.replaceChildren(...this.groups.map((group, index) => {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'rami__pending-group';
            button.dataset.group = String(index);
            button.setAttribute('aria-label', 'Remettre cette combinaison dans la main');
            button.append(...group.map((code) => this.cardElement(code)));

            return button;
        }));
    }

    // Ma main en éventail : chaque carte se choisit d'un clic ; la carte prise dans la défausse est signalée
    renderHand() {
        const cards = this.freeHand();

        this.hand.style.setProperty('--n', String(cards.length));
        this.hand.replaceChildren(...cards.map((code, index) => {
            const item = document.createElement('li');
            const card = this.cardElement(code, 'button');

            item.className = 'rami__hand-item';
            item.style.setProperty('--i', String(index));
            card.type = 'button';
            card.classList.add('rami__card--hand');
            card.classList.toggle('rami__card--selected', this.selected.has(code));
            card.classList.toggle('rami__card--taken', code === this.view.taken);
            card.setAttribute('aria-pressed', String(this.selected.has(code)));
            item.append(card);

            return item;
        }));
    }

    renderActions() {
        const selected = this.selected.size;

        this.groupButton.disabled = !this.canPlay || selected < 3;
        this.meldButton.disabled = !this.canPlay || (this.groups.length === 0 && selected < 3);
        this.discardButton.disabled = !this.canPlay || selected !== 1;
        this.giveBackButton.hidden = !this.view.taken;
        this.giveBackButton.disabled = !this.canPlay;
        this.hint.textContent = this.hintText();
    }

    hintText() {
        if (this.view.phase !== 'jeu') {
            return '';
        }

        if (!this.myTurn) {
            return `À ${this.player(this.view.turn)?.pseudo ?? 'un autre joueur'} de jouer.`;
        }

        if (this.view.step === 'pioche') {
            return 'À vous : prenez une carte dans la pioche ou sur la défausse.';
        }

        if (this.view.taken) {
            return 'Posez la carte prise dans la défausse, ou rendez-la.';
        }

        if (!this.opened) {
            const points = this.groups.length ? ` (préparé : ${this.groups.length} combinaison${this.groups.length > 1 ? 's' : ''})` : '';

            return `Première pose : ${this.view.firstMeld} points dont une suite${points}. Ou défaussez une carte.`;
        }

        return 'Posez, complétez une combinaison (une carte choisie, puis la combinaison), ou défaussez.';
    }

    // Fin d'une manche : les pénalités de chacun, quelques secondes ; fin de partie : le classement, jusqu'au retour aux salons
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
            const by = lastRound.winner === null ? 'Plus de cartes à piocher' : (lastRound.winner === this.view.me ? 'Vous finissez' : `${this.player(lastRound.winner)?.pseudo} finit`);
            title.textContent = `Manche ${lastRound.number} : ${by}${lastRound.ramiSec ? ' en rami sec !' : ''}`;
        }

        list.replaceChildren(...[...this.view.players].sort((a, b) => a.score - b.score).map((player) => {
            const row = document.createElement('li');
            const penalty = lastRound?.penalties[player.position];

            row.className = 'rami__result';
            row.textContent = `${player.position === this.view.me ? 'Vous' : player.pseudo} : ${player.score} pts${!finished && penalty !== undefined ? ` (+${penalty})` : ''}`;

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
            // Le joueur dont c'est le tour d'abord ; les autres un peu plus tard, s'il a fermé sa page
            this.clockTimer = window.setTimeout(() => this.sendTimeout(), this.myTurn ? 1200 : 2500);

            return;
        }

        this.clockTimer = window.setTimeout(this.tick, 250);
    }

    sendTimeout() {
        if (this.timeoutSent) {
            return;
        }

        this.timeoutSent = true;
        this.send('temps-ecoule', {}, true);
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const target = event.target;
        const handCard = target.closest('.rami__card--hand');
        const meld = target.closest('.rami__meld');
        const group = target.closest('.rami__pending-group');

        if (handCard) {
            this.toggle(handCard.dataset.card);
        } else if (group) {
            this.groups.splice(Number(group.dataset.group), 1);
            this.render(this.view);
        } else if (meld && !meld.disabled) {
            this.addTo(Number(meld.dataset.meld));
        } else if (target.closest('.rami__pile--stock')) {
            this.send('pioche', {});
        } else if (target.closest('.rami__pile--discard')) {
            this.onDiscardPile();
        } else if (target.closest('.rami__button--group')) {
            this.groups.push([...this.selected]);
            this.selected.clear();
            this.render(this.view);
        } else if (target.closest('.rami__button--meld')) {
            this.meld();
        } else if (target.closest('.rami__button--discard')) {
            this.discard();
        } else if (target.closest('.rami__button--give-back')) {
            this.send('defausse/rendre', {});
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

    toggle(code) {
        if (this.selected.has(code)) {
            this.selected.delete(code);
        } else {
            this.selected.add(code);
        }

        this.render(this.view, false);
    }

    onDiscardPile() {
        if (this.canDraw) {
            this.send('defausse/prendre', {});
        } else {
            this.discard();
        }
    }

    meld() {
        const groups = [...this.groups, ...(this.selected.size >= 3 ? [[...this.selected]] : [])];

        if (groups.length > 0) {
            this.send('poses', { groups }).then((done) => {
                if (done) {
                    this.groups = [];
                    this.selected.clear();
                    this.render(this.view, false);
                }
            });
        }
    }

    // Compléter la combinaison avec la carte choisie, ou y reprendre le joker qui la remplace
    addTo(index) {
        const [card] = [...this.selected];
        const meld = this.view.melds.find((item) => item.index === index);
        const swap = meld && !isJoker(card) && meld.cards.some((entry) => isJoker(entry.code) && this.jokerStandsFor(meld, entry, card));

        this.send(swap ? 'joker' : 'ajouts', { card, meld: index });
    }

    // Suite : le joker tient la place de cette carte ; série : de cette hauteur, dans une couleur absente
    jokerStandsFor(meld, entry, card) {
        if (meld.kind === 'suite') {
            return entry.face === faceOf(card);
        }

        const [suit, rank] = card.split('-');
        const naturals = meld.cards.filter((item) => !isJoker(item.code)).map((item) => item.face.split('-')[0]);

        return entry.face.split('-')[1] === rank && !naturals.includes(suit);
    }

    discard() {
        const [card] = [...this.selected];

        if (this.selected.size === 1) {
            this.send('defausse', { card });
        }
    }

    // ---------- Échanges avec le serveur ----------

    // Renvoie true si le coup est accepté
    async send(action, body, quiet = false) {
        if (this.busy) {
            return false;
        }

        this.busy = true;
        this.renderActions();

        try {
            const response = await fetch(`${this.apiUrl}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            this.busy = false;

            if (response.ok) {
                if (action === 'defausse' || action === 'ajouts' || action === 'joker') {
                    this.selected.clear();
                }

                this.render(payload.view);

                return true;
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

        return false;
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
