// Table du Président (disposition A). Le serveur décide de tout : la page envoie « je pose ces cartes »,
// « je passe », « je donne ces cartes », puis affiche la vue renvoyée par le serveur, qui fait foi.
// Le joueur choisit ses cartes d'un clic (elles se lèvent) puis appuie sur « Poser » : un clic ne pose jamais rien.
// Les cartes glissent de leur ancienne place à la nouvelle (rien ne saute) ; celles des autres viennent de leur place.

import { BackGuard, LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';
import Sound, { SoundButton } from '../Sound.js';

const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const RANK_LABELS = { as: 'As', valet: 'Valet', dame: 'Dame', roi: 'Roi' };
const RANK_PLURALS = { as: 'As', valet: 'Valets', dame: 'Dames', roi: 'Rois' };
const ROLE_LABELS = {
    president: 'Président',
    'vice-president': 'Vice-président',
    neutre: 'Neutre',
    'vice-pauvre': 'Vice-pauvre',
    pauvre: 'Pauvre',
};
// Places des adversaires autour de la table, selon leur nombre (du joueur suivant, à gauche, jusqu'au précédent, à droite)
const SEATS = {
    2: ['top-left', 'top-right'],
    3: ['left', 'top', 'right'],
    4: ['left', 'top-left', 'top-right', 'right'],
    5: ['left', 'top-left', 'top', 'top-right', 'right'],
    6: ['left', 'top-far-left', 'top-mid-left', 'top-mid-right', 'top-far-right', 'right'],
};
// Poses du pli montrées au centre (les plus anciennes, légèrement en retrait)
const SHOWN_PLAYS = 4;
const MAX_BACKS = 10;
const MOVE_DURATION = 260;
const RESULTS_DURATION = 4500;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

const rankOf = (code) => code.split('-')[1];

export default class PresidentTable {
    static init(root = document) {
        root.querySelectorAll('.president').forEach((element) => new PresidentTable(element).start());
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
        this.trick = element.querySelector('.president__trick');
        this.need = element.querySelector('.president__need');
        this.me = element.querySelector('.president__me');
        this.hand = element.querySelector('.rami__hand');
        this.hint = element.querySelector('.rami__hint');
        this.playButton = element.querySelector('.president__play');
        this.passButton = element.querySelector('.president__pass');
        this.clockFill = element.querySelector('.rami__clock-fill');
        this.scoreValue = element.querySelector('.rami__score-value');
        this.roundValue = element.querySelector('.rami__round');
        this.overlay = element.querySelector('.rami__overlay');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.rami__leave-dialog'), leaveUrl: dataset.leaveUrl, lobbyUrl: dataset.lobbyUrl, csrf: this.csrf });

        // Cartes choisies (levées), dans l'ordre du choix
        this.selected = [];
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

    // Échange : le nombre de cartes que je dois encore rendre (0 : rien)
    get giving() {
        return this.view.phase === 'echange' ? this.view.exchange.toGive : 0;
    }

    player(position) {
        return this.view.players.find((player) => player.position === position);
    }

    pseudo(position) {
        return position === this.view.me ? 'Vous' : (this.player(position)?.pseudo ?? 'Un joueur');
    }

    // ---------- Affichage ----------

    // Affiche la vue ; une annonce arrivée entre-temps (pendant un coup du joueur) est rattrapée aussitôt
    render(view, animate = true) {
        this.paint(view, animate);
        this.catchUp();
    }

    paint(view, animate = true) {
        const before = animate ? this.positions() : new Map();
        const seats = animate ? this.seatPositions() : new Map();

        // Nouvelle vue du serveur : le temps du tour repart d'elle
        if (view !== this.view || this.deadline === 0) {
            this.deadline = performance.now() + view.secondsLeft * 1000;
            this.timeoutSent = false;
        }

        // Nouvelle donne (nouvelle manche) : le bruit des cartes distribuées
        if (animate && view.round !== this.view.round) {
            Sound.deal(Math.min(52, view.hand.length * view.players.length));
        }

        this.view = view;
        // Les cartes choisies qui ne sont plus dans la main (posées, données) sont oubliées
        this.selected = this.selected.filter((code) => view.hand.includes(code) && (this.myTurn || this.giving > 0));

        this.roundValue.textContent = String(view.round);
        this.scoreValue.textContent = String(this.player(view.me)?.score ?? 0);
        this.renderOpponents();
        this.renderTrick();
        this.renderHand();
        this.renderActions();
        this.renderResults();
        this.tick();

        if (animate) {
            this.slide(before, seats);
        }
    }

    positions() {
        const positions = new Map();

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => positions.set(card.dataset.card, card.getBoundingClientRect()));

        return positions;
    }

    // Où se trouve chaque adversaire : ses cartes posées partent de là
    seatPositions() {
        const seats = new Map();

        this.opponents.querySelectorAll('.president__opponent').forEach((seat) => {
            seats.set(Number(seat.dataset.position), seat.querySelector('.president__fan').getBoundingClientRect());
        });

        return seats;
    }

    // Chaque carte glisse de son ancienne place à la nouvelle ; une carte posée par un autre part de sa place
    slide(before, seats) {
        if (REDUCED.matches) {
            return;
        }

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => {
            const from = before.get(card.dataset.card) ?? seats.get(Number(card.dataset.from));

            if (!from) {
                card.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });

                return;
            }

            const to = card.getBoundingClientRect();
            const dx = from.left + from.width / 2 - (to.left + to.width / 2);
            const dy = from.top + from.height / 2 - (to.top + to.height / 2);

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

    // « le 9 », « les Dames » : la pose à battre
    rankLabel(rank, count) {
        if (count > 1) {
            return `les ${RANK_PLURALS[rank] ?? rank}`;
        }

        return { dame: 'la Dame', valet: 'le Valet', roi: 'le Roi', as: 'l’As' }[rank] ?? `le ${rank}`;
    }

    // Les autres joueurs autour de la table : leurs cartes de dos, leur nom, leur titre, et « Passe » ou leur place
    renderOpponents() {
        const { players, me, turn, phase } = this.view;
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
            const status = document.createElement('p');
            const role = document.createElement('p');
            const backs = Math.min(player.cards, MAX_BACKS);
            const playing = phase === 'jeu' && player.position === turn;

            element.className = `president__opponent president__opponent--${SEATS[others.length][index]}${playing ? ' president__opponent--turn' : ''}`;
            element.dataset.position = String(player.position);
            fan.className = 'president__fan';
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

            // « Passe », sa place s'il a fini, ou « Choisit ses cartes » pendant l'échange : toujours la même place réservée
            status.className = 'president__status';
            status.textContent = this.statusOf(player);
            status.classList.toggle('president__status--visible', status.textContent !== '');
            // Son titre de la manche précédente (place réservée dès la première manche)
            role.className = `president__role${player.role ? ` president__role--${player.role}` : ''}`;
            role.textContent = player.role ? ROLE_LABELS[player.role] : '';
            element.append(role, fan, tag, status);

            return element;
        }));
    }

    statusOf(player) {
        if (player.place !== null) {
            return player.place === 1 ? '1er' : `${player.place}e`;
        }

        if (player.giving > 0) {
            return 'Choisit ses cartes…';
        }

        return player.passed ? 'Passe' : '';
    }

    // Le pli au centre : les dernières poses, la plus récente devant ; le pli écarté reste visible en grisé
    renderTrick() {
        const { trick, cleared } = this.view;
        const plays = trick.length ? trick : cleared;
        const shown = plays.slice(-SHOWN_PLAYS);

        this.trick.classList.toggle('president__trick--cleared', !trick.length && cleared.length > 0);
        this.trick.replaceChildren(...shown.map((play, index) => {
            const item = document.createElement('li');
            const cards = document.createElement('div');
            const who = document.createElement('p');

            item.className = 'president__play-group';
            item.style.setProperty('--depth', String(shown.length - 1 - index));
            cards.className = 'president__play-cards';
            cards.append(...play.cards.map((code) => {
                const card = this.cardElement(code);
                card.dataset.from = String(play.p);

                return card;
            }));
            who.className = 'president__play-who';
            who.textContent = this.pseudo(play.p);
            item.append(cards, who);

            return item;
        }));

        const last = trick.length ? trick[trick.length - 1] : null;
        this.need.textContent = last && this.view.phase === 'jeu'
            ? `À battre : ${last.cards.length > 1 ? `${last.cards.length} cartes plus fortes que ${this.rankLabel(rankOf(last.cards[0]), last.cards.length)}` : `une carte plus forte que ${this.rankLabel(rankOf(last.cards[0]), 1)}`}`
            : '';
    }

    // Ma main en éventail : les cartes choisies se lèvent ; les cartes reçues à l'échange sont marquées
    renderHand() {
        const cards = this.view.hand;
        const received = new Set(this.view.exchange.received);
        const active = (this.myTurn || this.giving > 0) && !this.busy;

        this.hand.style.setProperty('--n', String(cards.length));
        this.hand.replaceChildren(...cards.map((code, index) => {
            const item = document.createElement('li');
            const card = this.cardElement(code, 'button');

            item.className = 'rami__hand-item';
            item.style.setProperty('--i', String(index));
            card.type = 'button';
            card.classList.add('rami__card--hand');
            card.classList.toggle('rami__card--selected', this.selected.includes(code));
            card.classList.toggle('rami__card--taken', received.has(code) && this.view.round > 1);
            card.classList.toggle('president__card--idle', !active);
            card.setAttribute('aria-pressed', String(this.selected.includes(code)));
            item.append(card);

            return item;
        }));
    }

    renderActions() {
        const { phase, players, me } = this.view;
        const mine = this.player(me);
        const leading = this.myTurn && this.view.trick.length === 0;

        this.me.textContent = mine?.role ? `Vous êtes ${ROLE_LABELS[mine.role]}` : '';
        this.playButton.textContent = this.giving > 0 ? 'Donner' : 'Poser';
        this.playButton.hidden = !(this.myTurn || this.giving > 0);
        this.playButton.disabled = this.busy;
        this.passButton.hidden = !this.myTurn || leading;
        this.passButton.disabled = this.busy;

        if (phase === 'terminee') {
            this.hint.textContent = '';
        } else if (this.giving > 0) {
            const to = this.player(this.view.exchange.giveTo)?.pseudo ?? 'votre partenaire d’échange';
            this.hint.textContent = `Choisissez ${this.giving === 1 ? 'la carte' : `les ${this.giving} cartes`} à donner à ${to}, puis « Donner ».`;
        } else if (phase === 'echange') {
            const waiting = players.filter((player) => player.giving > 0).map((player) => player.pseudo);
            this.hint.textContent = `Échange des cartes : ${waiting.join(' et ')} ${waiting.length > 1 ? 'choisissent' : 'choisit'} les cartes à rendre.`;
        } else if (!this.myTurn) {
            this.hint.textContent = mine?.place ? `Vous avez fini : ${mine.place === 1 ? '1er' : `${mine.place}e`}.` : `À ${this.player(this.view.turn)?.pseudo ?? 'un autre joueur'} de jouer.`;
        } else if (leading) {
            this.hint.textContent = 'Vous avez la main : choisissez une ou plusieurs cartes de même valeur, puis « Poser ».';
        } else {
            this.hint.textContent = 'À vous : posez des cartes plus fortes, ou passez.';
        }
    }

    // Fin d'une manche : les places et les points, quelques secondes ; fin de partie : le classement, jusqu'au retour aux salons
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
            title.textContent = this.view.winner === this.view.me ? 'Vous avez gagné !' : `${this.player(this.view.winner)?.pseudo ?? 'Un autre joueur'} gagne la partie`;
            list.replaceChildren(...[...this.view.players].sort((a, b) => b.score - a.score || a.position - b.position).map((player) => {
                const row = document.createElement('li');
                row.className = 'rami__result';
                row.textContent = `${this.pseudo(player.position)} : ${player.score} pts`;

                return row;
            }));
        } else {
            const president = lastRound.order[0];
            title.textContent = `Manche ${lastRound.number} : ${president === this.view.me ? 'vous devenez Président' : `${this.pseudo(president)} devient Président`}`;
            list.replaceChildren(...lastRound.order.map((position) => {
                const row = document.createElement('li');
                const player = this.player(position);
                row.className = 'rami__result';
                row.textContent = `${ROLE_LABELS[player?.role] ?? ''} · ${this.pseudo(position)} : +${lastRound.points[position]} (${player?.score ?? 0} pts)`;

                return row;
            }));
        }

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

        if (this.view.phase === 'terminee') {
            this.clockFill.style.setProperty('--left', '0');

            return;
        }

        const left = Math.max(0, (this.deadline - performance.now()) / 1000);
        this.clockFill.style.setProperty('--left', (left / this.view.seconds).toFixed(4));
        this.clockFill.classList.toggle('rami__clock-fill--late', left <= 5);

        if (left <= 0) {
            this.clockTimer = window.setTimeout(() => this.sendTimeout(), this.myTurn || this.giving > 0 ? 1200 : 2500);

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

        if (card) {
            this.choose(card.dataset.card);
        } else if (target.closest('.president__play')) {
            this.confirm();
        } else if (target.closest('.president__pass')) {
            this.selected = [];
            this.send('passe', {});
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

    // Un clic lève ou rabaisse une carte. Pour poser, les cartes levées sont de même valeur : une carte d'une autre
    // valeur remplace le choix. À l'échange, on lève au plus le nombre de cartes à donner.
    choose(code) {
        if (this.busy) {
            return;
        }

        if (this.giving === 0 && !this.myTurn) {
            this.toast.show(this.view.phase === 'echange' ? 'Attendez la fin de l’échange des cartes.' : 'Ce n’est pas votre tour.', 2000);

            return;
        }

        if (this.selected.includes(code)) {
            this.selected = this.selected.filter((selected) => selected !== code);
        } else if (this.giving > 0) {
            this.selected = [...this.selected, code].slice(-this.giving);
        } else {
            const same = this.selected.filter((selected) => rankOf(selected) === rankOf(code));
            this.selected = [...same, code];
        }

        this.renderHand();
    }

    confirm() {
        if (this.giving > 0) {
            if (this.selected.length !== this.giving) {
                this.toast.show(this.giving === 1 ? 'Choisissez 1 carte à donner.' : `Choisissez ${this.giving} cartes à donner.`, 2400);

                return;
            }

            this.send('echange', { cards: this.selected });

            return;
        }

        if (!this.selected.length) {
            this.toast.show('Choisissez d’abord les cartes à poser (un clic sur chaque carte).', 2400);

            return;
        }

        this.send('cartes', { cards: this.selected });
    }

    // ---------- Échanges avec le serveur ----------

    async send(action, body, quiet = false) {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.renderHand();
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
                this.selected = [];
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
                this.renderHand();
                this.renderActions();
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
