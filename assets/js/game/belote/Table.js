// Table de belote. Le serveur décide de tout : la page envoie « je prends / je passe / je joue
// telle carte », puis rejoue un par un les événements reçus (robots compris) avec des animations,
// et termine sur la vue renvoyée par le serveur, qui fait foi.
import Animator, { wait } from './Animator.js';

// Sens des aiguilles d'une montre : après moi, le joueur à ma gauche
const SIDES = ['bottom', 'left', 'top', 'right'];
const SUITS = ['pique', 'coeur', 'trefle', 'carreau'];
const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const TRUMP_ORDER = ['7', '8', 'dame', 'roi', '10', 'as', '9', 'valet'];
const PLAIN_ORDER = ['7', '8', '9', 'valet', 'dame', 'roi', '10', 'as'];
const DEAL_STAGGER = 40;
const PACKET_PAUSE = 120;
// Orientation des cartes devant chaque joueur
const ANGLES = { bottom: 0, right: -90, top: 180, left: 90 };
// Inclinaison des cartes posées dans le pli (comme dans le CSS)
const TRICK_TILTS = { bottom: -3, right: 10, top: 4, left: -11 };
const STORAGE_KEY = 'playora-tapis';
const CARPETS = ['lagon', 'vert', 'nuit'];
// Titre de fin de donne, vu de mon équipe : [nous avons pris, ils ont pris]
const OUTCOMES = {
    reussi: ['Contrat rempli !', 'Ils ont fait leur contrat'],
    chute: ['Dedans ! Contrat chuté', 'Ils sont dedans !'],
    litige: ['Litige', 'Litige'],
};

export default class Table {
    static init(root = document) {
        root.querySelectorAll('.belote').forEach((element) => new Table(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.cardsUrl = dataset.cardsUrl;
        this.suitsUrl = dataset.suitsUrl;
        this.view = JSON.parse(dataset.view);
        this.live = structuredClone(this.view);

        this.players = element.querySelector('.belote__players');
        this.trick = element.querySelector('.belote__trick');
        this.bidding = element.querySelector('.bidding');
        this.biddingCard = element.querySelector('.bidding__card');
        this.biddingTitle = element.querySelector('.bidding__title');
        this.biddingText = element.querySelector('.bidding__text');
        this.biddingActions = element.querySelector('.bidding__actions');
        this.result = element.querySelector('.result');
        this.hand = element.querySelector('.hand');
        this.toast = element.querySelector('.belote__toast');
        this.lastTrick = element.querySelector('.last-trick');
        this.lastTrickToggle = element.querySelector('.last-trick__toggle');
        this.score = element.querySelector('.score');
        this.end = element.querySelector('.belote__end');
        this.playerTemplate = element.querySelector('#modele-joueur');
        this.animator = new Animator(element.querySelector('.belote__flights'));

        this.queue = Promise.resolve();
        this.busy = false;
        this.pendingCard = null;
        this.timerKey = null;
        this.timerAnimation = null;
        this.timeoutTimer = null;
        this.toastTimer = null;
        this.dealing = false;

        this.onClick = this.onClick.bind(this);
        this.onUpdate = this.onUpdate.bind(this);
        this.fitToScreen = this.fitToScreen.bind(this);
    }

    start() {
        this.element.addEventListener('click', this.onClick);
        window.addEventListener('resize', this.fitToScreen);
        this.fitToScreen();
        this.measureCards();
        this.applyCarpet(this.storedCarpet());
        this.buildPlayers();
        this.render();
        this.queue = this.queue.then(() => this.introDeal());

        if (this.hubUrl && 'EventSource' in window) {
            new EventSource(this.hubUrl).addEventListener('message', this.onUpdate);
        }
    }

    // La table occupe tout l'écran sous l'en-tête du site, quelle que soit sa hauteur
    fitToScreen() {
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        this.element.style.setProperty('--offset', `${Math.round(top)}px`);
        this.measureCards();
    }

    // Tailles des cartes (main, pli, dos) : elles changent avec l'écran
    measureCards() {
        this.widths = {
            hand: this.animator.measure('--card-w'),
            trick: this.animator.measure('--trick-w'),
            back: this.animator.measure('--back-w'),
        };
    }

    // Là où arrivent les cartes d'un joueur : sa main (moi) ou son éventail de dos
    seatBox(position) {
        return position === this.me
            ? this.animator.box(this.hand, this.widths.hand)
            : this.animator.box(this.seat(position).querySelector('.player__fan'), this.widths.back);
    }

    angle(position) {
        return ANGLES[this.side(position)];
    }

    // ---------- Places ----------

    get me() {
        return this.view.me;
    }

    side(position) {
        return SIDES[(position - this.me + 4) % 4];
    }

    player(position) {
        return this.view.players[position];
    }

    seat(position) {
        return this.players.querySelector(`.player--${this.side(position)}`);
    }

    buildPlayers() {
        const seats = this.view.players.map((player) => {
            const element = this.playerTemplate.content.firstElementChild.cloneNode(true);
            const ring = element.querySelector('.player__ring');

            element.classList.add(`player--${this.side(player.position)}`);
            element.querySelector('.player__name').textContent = player.pseudo;

            if (player.avatar) {
                const image = document.createElement('img');
                image.className = 'player__avatar';
                image.src = player.avatar;
                image.alt = '';
                image.width = 96;
                image.height = 96;
                ring.append(image);
            } else {
                const initial = document.createElement('span');
                initial.className = 'player__initial';
                initial.textContent = player.pseudo.charAt(0).toUpperCase();
                ring.append(initial);
            }

            return element;
        });

        this.players.replaceChildren(...seats);
    }

    suitIcon(suit, size) {
        const icon = document.createElement('img');
        icon.src = `${this.suitsUrl}couleur-${suit}.svg`;
        icon.alt = '';
        icon.width = size;
        icon.height = size;

        return icon;
    }

    // ---------- Cartes ----------

    cardElement(code) {
        const card = document.createElement('span');
        const face = document.createElement('img');
        const [suit, rank] = code ? code.split('-') : [];

        card.className = code ? 'card' : 'card card--back';
        face.className = 'card__face';
        face.src = code ? `${this.cardsUrl}${suit}/${rank}.svg` : `${this.cardsUrl}dos.svg`;
        face.alt = code ? `${rank} de ${SUIT_LABELS[suit]}` : '';
        face.draggable = false;
        card.append(face);

        return card;
    }

    sortHand(codes) {
        const trump = this.live.trump;
        const suits = trump ? [trump, ...SUITS.filter((suit) => suit !== trump)] : SUITS;

        return [...codes].sort((a, b) => {
            const [suitA, rankA] = a.split('-');
            const [suitB, rankB] = b.split('-');
            const order = (suit) => (suit === trump ? TRUMP_ORDER : PLAIN_ORDER);

            return suits.indexOf(suitA) - suits.indexOf(suitB) || order(suitB).indexOf(rankB) - order(suitA).indexOf(rankA);
        });
    }

    // ---------- Affichage ----------

    render() {
        this.renderPlayers();
        this.renderTrick();
        this.renderHand();
        this.renderBidding();
        this.renderScore();
        this.renderTimer();
    }

    renderPlayers() {
        const { counts, turn, taker, trump, phase } = this.live;
        const points = this.live.points ?? [0, 0, 0, 0];
        const first = this.firstPlayer();

        this.view.players.forEach((player) => {
            const seat = this.seat(player.position);
            const contract = seat.querySelector('.player__contract');
            const pile = seat.querySelector('.player__pile');
            const cards = Array.from({ length: counts[player.position] }, (_, index) => {
                const card = this.cardElement(null);
                card.style.setProperty('--i', String(index));
                card.style.setProperty('--n', String(counts[player.position]));

                return card;
            });

            seat.querySelector('.player__fan').replaceChildren(...cards);
            seat.classList.toggle('player--turn', phase !== 'terminee' && turn === player.position);

            // Pastille d'atout sur le preneur
            const taking = taker === player.position && Boolean(trump);
            contract.classList.toggle('player__contract--visible', taking);
            contract.replaceChildren(...(taking ? [this.suitIcon(trump, 20)] : []));

            // Score provisoire de la donne (points des plis remportés) et pastille « 1er » de celui qui entame
            pile.classList.toggle('player__pile--empty', points[player.position] === 0);
            pile.querySelector('.player__pile-count').textContent = String(points[player.position]);
            seat.classList.toggle('player--first', first === player.position);
        });
    }

    // Celui qui entame le pli : la première carte posée, ou celui qui doit jouer si le pli est vide
    firstPlayer() {
        const { phase, trick, turn } = this.live;

        if (phase !== 'jeu') {
            return null;
        }

        return trick.length > 0 ? trick[0][0] : turn;
    }

    renderTrick() {
        this.trick.replaceChildren(...this.live.trick.map(([position, code]) => this.trickCard(position, code)));
    }

    trickCard(position, code) {
        const card = this.cardElement(code);
        card.classList.add('trick__card', `trick__card--${this.side(position)}`, 'card--shadow');
        card.dataset.card = code;

        return card;
    }

    renderHand() {
        const codes = this.sortHand(this.live.hand);
        const myTurn = !this.busy && this.live.phase === 'jeu' && this.live.turn === this.me;
        const playable = new Set(myTurn ? this.live.playable : []);

        this.hand.classList.toggle('hand--active', myTurn);
        this.hand.replaceChildren(...codes.map((code, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'hand__card';
            button.dataset.card = code;
            button.classList.toggle('hand__card--playable', playable.has(code));
            button.disabled = myTurn && !playable.has(code);
            button.style.setProperty('--i', String(index));
            button.style.setProperty('--n', String(codes.length));
            button.append(this.cardElement(code));
            item.append(button);

            return item;
        }));
    }

    renderBidding() {
        const { phase, turned, turn } = this.live;
        const bidding = phase === 'encheres-1' || phase === 'encheres-2';
        const visible = bidding && Boolean(turned) && !this.dealing;
        const second = phase === 'encheres-2';

        this.bidding.classList.toggle('bidding--visible', visible);
        this.bidding.classList.toggle('bidding--second', second && visible && turn === this.me && !this.busy);
        this.biddingCard.replaceChildren(...(bidding && turned ? [this.cardElement(turned)] : []));
        this.biddingActions.replaceChildren();
        this.biddingTitle.textContent = '';
        this.biddingText.textContent = '';

        if (!visible) {
            return;
        }

        const turnedSuit = turned.split('-')[0];

        if (turn !== this.me || this.busy) {
            this.biddingText.textContent = turn === this.me ? '' : `${this.player(turn).pseudo} réfléchit…`;

            return;
        }

        if (second) {
            this.biddingTitle.textContent = 'Deuxième tour';
            this.biddingText.textContent = 'Choisissez une autre couleur ou passez';
            SUITS.filter((suit) => suit !== turnedSuit).forEach((suit) => {
                this.biddingActions.append(this.bidButton(null, suit, 'bidding__button--suit'));
            });
        } else {
            this.biddingTitle.textContent = 'À vous de parler';
            this.biddingText.textContent = 'Premier tour · la carte retournée donne l’atout';
            this.biddingActions.append(this.bidButton('Prendre à', turnedSuit, 'bidding__button--take'));
        }

        this.biddingActions.append(this.bidButton('Passer', null, 'bidding__button--pass'));
    }

    // label null : tuile avec la seule couleur (second tour)
    bidButton(label, suit, modifier) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `bidding__button ${modifier}`;
        button.dataset.take = suit ? '1' : '0';

        if (label) {
            button.append(label);
        }

        if (suit) {
            button.dataset.suit = suit;
            button.append(this.suitIcon(suit, 20));
            button.setAttribute('aria-label', `${label ?? 'Prendre à'} ${SUIT_LABELS[suit]}`);
        }

        return button;
    }

    // Tableau des scores : une ligne par donne (vue de mon équipe), puis le total
    renderScore() {
        const { scores, dealScores = [], lastTrick } = this.live;
        const us = this.player(this.me).team;
        const rows = dealScores.map((scored, index) => {
            const row = document.createElement('tr');
            const number = document.createElement('th');
            number.scope = 'row';
            number.textContent = String(index + 1);
            row.append(number);
            [scored[us], scored[1 - us]].forEach((points) => {
                const cell = document.createElement('td');
                cell.textContent = String(points);
                row.append(cell);
            });

            return row;
        });

        this.score.querySelector('.score__rows').replaceChildren(...rows);
        this.score.querySelector('.score__us').textContent = String(scores[us]);
        this.score.querySelector('.score__them').textContent = String(scores[1 - us]);
        this.lastTrickToggle.disabled = lastTrick.length === 0;

        if (lastTrick.length === 0) {
            this.toggleLastTrick(false);
        } else if (this.lastTrick.classList.contains('last-trick--open')) {
            this.fillLastTrick();
        }
    }

    // Minuteur : l'anneau autour de l'avatar de celui qui joue se vide (affiché seulement pour l'instant)
    // Minuteur : l'anneau du joueur dont c'est le tour se vide ; à zéro, on prévient le serveur,
    // qui vérifie l'heure et joue à sa place. Les autres tables attendent un peu plus (un seul suffit).
    renderTimer() {
        const { turn, phase, version } = this.live;
        const key = `${version}-${turn}`;
        const running = phase !== 'terminee' && !this.busy;

        if (!running || key === this.timerKey) {
            return;
        }

        const total = this.view.turnSeconds * 1000;
        const left = Math.min(total, (this.view.secondsLeft ?? this.view.turnSeconds) * 1000);

        this.timerKey = key;
        this.timerAnimation?.cancel();
        window.clearTimeout(this.timeoutTimer);
        this.timerAnimation = this.seat(turn).querySelector('.player__ring').animate([{ '--ring': '100%' }, { '--ring': '0%' }], {
            duration: total,
            fill: 'forwards',
        });
        this.timerAnimation.currentTime = total - left;
        this.timeoutTimer = window.setTimeout(() => this.onTimeout(key), left + (turn === this.me ? 300 : 1500));
    }

    async onTimeout(key) {
        if (this.busy || key !== this.timerKey || this.view.players[this.live.turn].bot) {
            return;
        }

        this.busy = true;

        try {
            const response = await fetch(`${this.apiUrl}/temps-ecoule`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
            });
            const payload = await response.json().catch(() => ({}));

            if (response.ok) {
                this.enqueue(payload.events, payload.view);

                return;
            }

            // Déjà joué par quelqu'un d'autre, ou pas encore l'heure : on se remet à jour sans rien afficher
            this.queue = this.queue.then(async () => this.finish(payload.view ?? await this.fetchView() ?? this.view));
        } catch {
            this.busy = false;
        }
    }

    say(position, text) {
        const bubble = this.seat(position).querySelector('.player__bubble');
        bubble.textContent = text;
        bubble.classList.add('player__bubble--visible');
        window.setTimeout(() => bubble.classList.remove('player__bubble--visible'), 1300);
    }

    showToast(text, duration = 1800) {
        this.toast.textContent = text;
        this.toast.classList.add('belote__toast--visible');
        window.clearTimeout(this.toastTimer);
        this.toastTimer = window.setTimeout(() => this.toast.classList.remove('belote__toast--visible'), duration);
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const card = event.target.closest('.hand__card--playable');
        const bid = event.target.closest('.bidding__button');
        const carpet = event.target.closest('.carpets__choice');

        if (card && this.hand.classList.contains('hand--active')) {
            this.playCard(card);
        } else if (bid && !this.busy) {
            this.send('encheres', { take: bid.dataset.take === '1', suit: bid.dataset.suit ?? null });
        } else if (carpet) {
            this.applyCarpet(carpet.dataset.carpet);
        } else if (event.target.closest('.belote__tool--rules')) {
            this.element.querySelector('.belote__rules').showModal();
        } else if (event.target.closest('.belote__rules-close')) {
            this.element.querySelector('.belote__rules').close();
        } else if (event.target.closest('.belote__tool--fullscreen')) {
            this.toggleFullscreen();
        } else if (event.target.closest('.last-trick__toggle')) {
            this.toggleLastTrick(!this.lastTrick.classList.contains('last-trick--open'));
        } else if (event.target.closest('.score__head')) {
            const open = !this.score.classList.contains('score--open');
            this.score.classList.toggle('score--open', open);
            this.score.querySelector('.score__head').setAttribute('aria-expanded', String(open));
        }
    }

    // La carte part tout de suite vers le centre : le jeu répond instantanément
    async playCard(button) {
        const code = button.dataset.card;
        const from = this.animator.box(button.firstElementChild, this.widths.hand);
        const fan = Number.parseFloat(window.getComputedStyle(button).getPropertyValue('--fan')) || 0;
        const tilt = (Number(button.style.getPropertyValue('--i')) - (Number(button.style.getPropertyValue('--n')) - 1) / 2) * fan;

        this.pendingCard = code;
        this.live.hand = this.live.hand.filter((held) => held !== code);
        this.live.trick = [...this.live.trick, [this.me, code]];
        this.live.counts[this.me] -= 1;
        this.busy = true;
        this.renderHand();
        this.renderTimer();

        const slot = this.trickCard(this.me, code);
        this.trick.append(slot);
        slot.style.visibility = 'hidden';
        const flight = this.animator.fly(this.cardElement(code), from, this.animator.box(slot, this.widths.trick), { duration: 320, rotate: [tilt, TRICK_TILTS.bottom] });

        const request = this.send('cartes', { card: code });
        await flight;
        slot.style.visibility = '';
        await request;
    }

    async send(action, body) {
        this.busy = true;
        this.renderBidding();

        try {
            const response = await fetch(`${this.apiUrl}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.ok) {
                this.enqueue(payload.events, payload.view);

                return;
            }

            // Coup refusé par les règles (409, 422) : le message du serveur, en français, est pour le joueur.
            // Toute autre erreur reste technique : jamais affichée telle quelle, la table se remet à jour.
            const refused = response.status === 409 || response.status === 422;
            this.fail(refused ? payload.detail ?? 'Coup refusé.' : 'Un souci est survenu : la partie reprend.', refused ? payload.view : null);
        } catch {
            this.fail('Connexion perdue : la partie reprend.', null);
        }
    }

    fail(message, view) {
        this.pendingCard = null;
        this.showToast(message);
        this.queue = this.queue.then(async () => this.finish(view ?? await this.fetchView() ?? this.view));
    }

    // Vue à jour de la table (après une erreur) ; null si le serveur ne répond pas
    async fetchView() {
        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            return response.ok ? (await response.json()).view : null;
        } catch {
            return null;
        }
    }

    // Un autre joueur a joué (Mercure) : on récupère sa vue et ce qui s'est passé
    async onUpdate(message) {
        const { version } = JSON.parse(message.data);

        if (this.busy || version <= this.view.version) {
            return;
        }

        try {
            const response = await fetch(`${this.apiUrl}?depuis=${this.view.version}`, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                const payload = await response.json();
                this.enqueue(payload.events, payload.view);
            }
        } catch {
            // Réseau coupé : le prochain message relancera la mise à jour
        }
    }

    enqueue(events, view) {
        this.busy = true;
        this.queue = this.queue.then(async () => {
            for (const event of events) {
                await this.apply(event);
            }

            this.finish(view);
        });
    }

    finish(view) {
        this.view = view;
        this.live = structuredClone(view);
        this.busy = false;
        this.pendingCard = null;
        this.render();

        if (view.phase === 'terminee') {
            this.showEnd(view);
        }
    }

    // ---------- Événements (animations) ----------

    async apply(event) {
        const handlers = {
            dealt: () => this.onDealt(event),
            bid: () => this.onBid(event),
            secondRound: () => this.showToast('Second tour : on peut choisir une autre couleur'),
            redeal: async () => { this.showToast('Personne ne prend : on redistribue'); await wait(900); },
            completed: () => this.onCompleted(event),
            played: () => this.onPlayed(event),
            belote: async () => { this.say(event.position, `${event.word} !`); await wait(500); },
            trick: () => this.onTrick(event),
            dealEnd: () => this.onDealEnd(event),
            gameEnd: async () => {},
        };

        await handlers[event.type]?.();
    }

    async onDealt(event) {
        Object.assign(this.live, {
            deal: event.deal, dealer: event.dealer, phase: 'encheres-1', turned: null, trump: null, taker: null,
            trick: [], hand: [], counts: [0, 0, 0, 0], points: [0, 0, 0, 0], turn: (event.dealer + 1) % 4,
        });
        this.dealing = true;
        this.render();
        await this.dealFlights(event.dealer, [3, 2], event.counts, event.cards);
        await this.turnUp(event.dealer, event.turned);
        this.dealing = false;
        this.renderBidding();
        await wait(250);
    }

    // La carte du dessus du paquet est retournée au milieu de la table
    async turnUp(dealer, code) {
        this.live.turned = code;
        this.renderBidding();
        const card = this.biddingCard.firstElementChild;
        card.style.visibility = 'hidden';
        await this.animator.fly(this.cardElement(null), this.seatBox(dealer), this.animator.box(card), {
            duration: 460, rotate: [this.angle(dealer), 0], reveal: this.cardElement(code),
        });
        card.style.visibility = '';
    }

    async onBid(event) {
        const label = event.suit ? `Je prends à ${SUIT_LABELS[event.suit]}` : 'Je prends';
        this.say(event.position, event.take ? label : 'Je passe');
        this.live.turn = (event.position + 1) % 4;

        if (!event.take) {
            this.renderPlayers();
            this.renderBidding();
        }

        await wait(event.position === this.me ? 250 : 650);
    }

    async onCompleted(event) {
        const turned = this.biddingCard.firstElementChild;
        const from = this.animator.box(turned ?? this.trick, this.widths.trick * 1.15);

        this.live.trump = event.trump;
        this.live.taker = event.taker;
        this.live.turned = null;
        this.live.phase = 'jeu';
        this.renderBidding();
        this.renderPlayers();
        // La carte retournée rejoint le preneur, puis le donneur distribue le reste
        const mine = event.taker === this.me;
        await this.animator.fly(this.cardElement(event.turned), from, this.seatBox(event.taker), {
            duration: 420, rotate: [0, this.angle(event.taker)], reveal: mine ? null : this.cardElement(null),
        });
        this.land(event.taker, mine ? [event.turned] : []);

        await this.dealFlights(this.live.dealer, null, event.counts, event.cards);
        this.live.turn = (this.live.dealer + 1) % 4;
        this.render();
        await wait(250);
    }

    async onPlayed(event) {
        const mine = event.position === this.me;

        if (mine && this.pendingCard === event.card) {
            this.pendingCard = null;
        } else {
            const slot = this.trickCard(event.position, event.card);
            this.trick.append(slot);
            slot.style.visibility = 'hidden';
            const from = this.seatBox(event.position);
            this.live.counts[event.position] -= 1;

            if (mine) {
                this.live.hand = this.live.hand.filter((code) => code !== event.card);
                this.renderHand();
            } else {
                this.renderPlayers();
            }

            // Un adversaire sort la carte de son jeu face cachée et la retourne en la posant
            await this.animator.fly(mine ? this.cardElement(event.card) : this.cardElement(null), from, this.animator.box(slot, this.widths.trick), {
                duration: 420, rotate: [this.angle(event.position), TRICK_TILTS[this.side(event.position)]], reveal: mine ? null : this.cardElement(event.card),
            });
            slot.style.visibility = '';
            this.live.trick = [...this.live.trick, [event.position, event.card]];
        }

        this.live.turn = (event.position + 1) % 4;
        this.renderPlayers();
    }

    // Le pli reste un instant, le gagnant rassemble les cartes en paquet et le ramène vers lui, face cachée
    async onTrick(event) {
        await wait(700);
        const center = this.animator.box(this.trick, this.widths.trick);
        const to = this.seatBox(event.winner);
        const cards = [...this.trick.children];

        const pile = await Promise.all(cards.map((card, index) => {
            const from = this.animator.box(card, this.widths.trick);
            const spot = { ...center, x: center.x + (index - 1.5) * 2, y: center.y - index * 2 };
            card.style.visibility = 'hidden';

            return this.animator.fly(this.cardElement(card.dataset.card), from, spot, { duration: 240, rotate: [0, (index - 1.5) * 3], lift: 1, keep: true });
        }));

        await wait(120);
        await Promise.all(pile.filter(Boolean).map((card, index) => {
            const from = this.animator.box(card, this.widths.trick);

            return this.animator.fly(card, from, { ...to, width: this.widths.back }, {
                duration: 460, delay: index * 25, rotate: [0, this.angle(event.winner)], reveal: this.cardElement(null), fade: true, lift: 1,
            });
        }));

        this.live.lastTrick = this.live.trick;
        this.live.trick = [];
        this.live.turn = event.winner;
        this.live.points = (this.live.points ?? [0, 0, 0, 0]).map((total, position) => total + (position === event.winner ? event.points : 0));
        this.renderTrick();
        this.renderPlayers();
        this.renderScore();
    }

    // Fin de donne : le résultat s'affiche au centre du tapis
    async onDealEnd(event) {
        const { result, scores } = event;
        const us = this.player(this.me).team;
        const bonus = [];

        if (result.capotTeam !== null) {
            bonus.push('Capot !');
        }

        if (result.beloteTeam === us) {
            bonus.push('dont +20 Belote');
        }

        this.live.scores = scores;
        this.live.dealScores = [...(this.live.dealScores ?? []), result.scored];
        this.renderScore();

        const ourContract = this.player(result.taker).team === us;
        this.result.querySelector('.result__title').textContent = OUTCOMES[result.outcome][ourContract ? 0 : 1];
        this.result.querySelector('.result__taker').textContent = `Pris par ${this.player(result.taker).pseudo} à ${SUIT_LABELS[result.trump]}`;
        this.result.querySelector('.result__value').textContent = String(result.scored[us]);
        this.result.querySelector('.result__bonus').textContent = bonus.join(' · ');
        this.result.querySelector('.result__them').textContent = `Vos adversaires marquent ${result.scored[1 - us]} pts`;
        this.result.classList.add('result--visible');
        await wait(2600);
        this.result.classList.remove('result--visible');
        await wait(250);
    }

    showEnd(view) {
        const us = this.player(this.me).team;
        this.end.querySelector('.belote__end-title').textContent = view.winner === us ? 'Vous avez gagné !' : 'Vous avez perdu';
        this.end.querySelector('.belote__end-score').textContent = `${view.scores[us]} : ${view.scores[1 - us]}`;
        this.end.classList.add('belote__end--visible');
    }

    toggleLastTrick(open) {
        this.lastTrick.classList.toggle('last-trick--open', open);
        this.lastTrickToggle.setAttribute('aria-expanded', String(open));

        if (open) {
            this.fillLastTrick();
        }
    }

    fillLastTrick() {
        this.lastTrick.querySelector('.last-trick__cards').replaceChildren(...this.live.lastTrick.map(([, code]) => this.cardElement(code)));
    }

    // Distribution : les cartes partent du donneur, à gauche du donneur d'abord, paquet par paquet
    // (packets : [3, 2] pour la donne ; sinon le nombre de cartes de chacun en un seul paquet).
    // Mes cartes se retournent en arrivant dans ma main ; chaque carte posée met la table à jour.
    async dealFlights(dealer, packets, counts, cards) {
        const from = this.seatBox(dealer);
        const queue = [...cards];
        const flights = [];
        let delay = 0;

        for (const packet of packets ?? [null]) {
            for (let offset = 1; offset <= 4; offset += 1) {
                const position = (dealer + offset) % 4;
                const count = packet ?? counts[position];
                const to = this.seatBox(position);
                const mine = position === this.me;

                for (let i = 0; i < count; i += 1) {
                    const code = mine ? queue.shift() : null;
                    const flight = this.animator.fly(this.cardElement(null), from, to, {
                        duration: 380, delay, rotate: [this.angle(dealer), this.angle(position) + (i - 1) * 3], reveal: code ? this.cardElement(code) : null,
                    });
                    flights.push(flight.then(() => this.land(position, code ? [code] : [])));
                    delay += DEAL_STAGGER;
                }

                delay += PACKET_PAUSE;
            }
        }

        await Promise.all(flights);
    }

    // Une carte arrive chez un joueur : son éventail (ou ma main) grandit d'une carte
    land(position, codes) {
        this.live.counts[position] += 1;

        if (position === this.me) {
            this.live.hand = [...this.live.hand, ...codes];
            this.renderHand();
        } else {
            this.renderPlayers();
        }
    }

    // Première ouverture de la page : on rejoue la distribution
    async introDeal() {
        const key = `belote-intro-${this.view.id}`;

        try {
            if (window.sessionStorage.getItem(key) || this.view.deal !== 1 || this.view.trick.length > 0) {
                return;
            }

            window.sessionStorage.setItem(key, '1');
        } catch {
            return;
        }

        this.busy = true;
        this.dealing = true;
        const { hand, counts, turned } = this.live;
        this.live.hand = [];
        this.live.counts = [0, 0, 0, 0];
        this.live.turned = null;
        this.render();
        await this.dealFlights(this.view.dealer, turned ? [3, 2] : null, counts, hand);

        if (turned) {
            await this.turnUp(this.view.dealer, turned);
        }

        this.dealing = false;
        this.busy = false;
        this.render();
    }

    // Plein écran sur le plateau seul (et retour)
    toggleFullscreen() {
        const frame = this.element.querySelector('.belote__frame');

        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        } else {
            frame.requestFullscreen?.().catch(() => {});
        }
    }

    // ---------- Tapis ----------

    storedCarpet() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) ?? 'lagon';
        } catch {
            return 'lagon';
        }
    }

    applyCarpet(name) {
        const carpet = CARPETS.includes(name) ? name : 'lagon';

        CARPETS.forEach((item) => this.element.classList.toggle(`belote--${item}`, item === carpet));
        this.element.querySelectorAll('.carpets__choice').forEach((button) => {
            const active = button.dataset.carpet === carpet;
            button.classList.toggle('carpets__choice--active', active);
            button.setAttribute('aria-pressed', String(active));
        });

        try {
            window.localStorage.setItem(STORAGE_KEY, carpet);
        } catch {
            // Stockage indisponible : le choix vaut pour cette page seulement
        }
    }
}
