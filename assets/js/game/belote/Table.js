// Table de belote. Le serveur décide de tout : la page envoie « je prends / je passe / je joue
// telle carte », puis rejoue un par un les événements reçus (robots compris) avec des animations,
// et termine sur la vue renvoyée par le serveur, qui fait foi.
import Animator, { wait } from './Animator.js';
import { LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';

// Sens des aiguilles d'une montre : après moi, le joueur à ma gauche
const SIDES = ['bottom', 'left', 'top', 'right'];
const SUITS = ['pique', 'coeur', 'trefle', 'carreau'];
const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const SUIT_SIGNS = { pique: '♠', coeur: '♥', carreau: '♦', trefle: '♣' };
// Coinche : contrat en tout atout ou en sans atout (pas de couleur d'atout)
const MODES = { 'tout-atout': { short: 'TA', label: 'tout atout' }, 'sans-atout': { short: 'SA', label: 'sans atout' } };
const BIDDING_PHASES = ['encheres-1', 'encheres-2', 'annonces'];
// Coinche et contrée : capot annoncé
const NAMED_CONTRACTS = { 250: 'Capot', 500: 'Générale' };
const contractName = (contract) => NAMED_CONTRACTS[contract] ?? String(contract);
// Contrée : on « contre » ; coinche : on « coinche »
const WORDS = {
    coinche: { 2: 'coinché', 4: 'surcoinché', coinche: 'Coincher', surcoinche: 'Surcoincher', saidCoinche: 'Coinche !', saidSurcoinche: 'Surcoinche !' },
    contree: { 2: 'contré', 4: 'surcontré', coinche: 'Contrer', surcoinche: 'Surcontrer', saidCoinche: 'Contre !', saidSurcoinche: 'Surcontre !' },
};
const TRUMP_ORDER = ['7', '8', 'dame', 'roi', '10', 'as', '9', 'valet'];
const PLAIN_ORDER = ['7', '8', '9', 'valet', 'dame', 'roi', '10', 'as'];
const DEAL_STAGGER = 40;
const PACKET_PAUSE = 120;
// Rythme de la partie, le même sur tous les écrans de la table : une annonce, une carte jouée
export const BID_PAUSE = 650;
const PLAY_DURATION = 420;
// Orientation des cartes devant chaque joueur
const ANGLES = { bottom: 0, right: -90, top: 180, left: 90 };
// Inclinaison des cartes posées dans le pli (comme dans le CSS)
const TRICK_TILTS = { bottom: -3, right: 10, top: 4, left: -11 };
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
        this.backUrl = dataset.backUrl;
        this.suitsUrl = dataset.suitsUrl;
        this.leaveUrl = dataset.leaveUrl;
        this.lobbyUrl = dataset.lobbyUrl;
        this.view = JSON.parse(dataset.view);
        this.live = structuredClone(this.view);

        this.players = element.querySelector('.belote__players');
        this.trick = element.querySelector('.belote__trick');
        this.mat = element.querySelector('.belote__mat');
        this.bidding = element.querySelector('.bidding');
        this.biddingCard = element.querySelector('.bidding__card');
        this.biddingTitle = element.querySelector('.bidding__title');
        this.biddingText = element.querySelector('.bidding__text');
        this.biddingActions = element.querySelector('.bidding__actions');
        this.result = element.querySelector('.result');
        this.hand = element.querySelector('.hand');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.belote__leave'), leaveUrl: this.leaveUrl, lobbyUrl: this.lobbyUrl, csrf: this.csrf });
        this.declareButton = element.querySelector('.belote__declare');
        this.lastTrick = element.querySelector('.last-trick');
        this.lastTrickToggle = element.querySelector('.last-trick__toggle');
        this.score = element.querySelector('.score');
        this.end = element.querySelector('.belote__end');
        this.playerTemplate = element.querySelector('#modele-joueur');
        this.animator = new Animator(element.querySelector('.belote__flights'));

        this.queue = Promise.resolve();
        this.busy = false;
        this.pendingCard = null;
        // Dernière version annoncée par Mercure, et une récupération en cours (aucune annonce n'est perdue)
        this.latestVersion = 0;
        this.fetching = false;
        this.timerKey = null;
        this.timerAnimation = null;
        this.timeoutTimer = null;
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

    // Le centre du tapis : la distribution et la carte retournée partent d'ici, comme d'un paquet posé sur la table
    centerBox() {
        return this.animator.box(this.mat, this.widths.back);
    }

    angle(position) {
        return ANGLES[this.side(position)] ?? 180;
    }

    // Inclinaison d'une carte posée dans le pli, selon la place du joueur
    trickTilt(position) {
        return TRICK_TILTS[this.side(position)] ?? 0;
    }

    // Le joueur suivant (4 à la belote ; le tarot se joue aussi à 3 ou 5)
    nextPosition(position) {
        return (position + 1) % this.view.players.length;
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
        face.src = code ? `${this.cardsUrl}${suit}/${rank}.svg` : this.backUrl;
        face.alt = code ? `${rank} de ${SUIT_LABELS[suit]}` : '';
        face.draggable = false;
        card.append(face);

        return card;
    }

    // Atout à gauche ; au premier tour d'enchères, la couleur proposée (carte retournée) à gauche pour mieux décider
    sortHand(codes) {
        const { phase, turned } = this.live;
        const trump = this.live.trump ?? (phase === 'encheres-1' && turned ? turned.split('-')[0] : null);
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
        this.renderDeclare();
    }

    // ---------- Annonces ----------

    // Le bouton n'apparaît qu'à mon tour, avant ma première carte, si j'ai une annonce
    renderDeclare() {
        const names = this.live.declarable ?? [];
        const visible = !this.busy && names.length > 0 && this.live.phase === 'jeu' && this.live.turn === this.me;

        if (visible) {
            this.declareButton.textContent = `Annoncer : ${names.join(' + ')}`;
        }

        this.declareButton.classList.toggle('belote__declare--visible', visible);
        this.declareButton.tabIndex = visible ? 0 : -1;
        this.declareButton.setAttribute('aria-hidden', String(!visible));
    }

    async onDeclared(event) {
        this.say(event.position, event.names.join(' + '));
        await wait(700);
    }

    // Fin du premier pli : les annonces sont montrées, une seule équipe les marque
    async onAnnouncements(event) {
        if (event.team === null) {
            this.showToast('Annonces de même valeur : elles s’annulent', 2400);
        } else {
            const ours = event.team === this.player(this.me).team;
            this.showToast(`${ours ? 'Votre équipe marque' : 'Vos adversaires marquent'} ${event.points} pts d’annonces`, 2400);
        }

        Object.entries(event.declarations).forEach(([position, list]) => {
            this.say(Number(position), list.map((announcement) => announcement.name).join(' + '));
        });
        await wait(1300);
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
            const mode = MODES[this.live.mode];
            const taking = taker === player.position && (Boolean(trump) || Boolean(mode));
            contract.classList.toggle('player__contract--visible', taking);
            contract.classList.toggle('player__contract--value', taking && Boolean(this.live.contract));
            contract.replaceChildren(...(taking && trump ? [this.suitIcon(trump, 20)] : []));

            if (taking && mode) {
                const short = document.createElement('span');
                short.className = 'player__contract-mode';
                short.textContent = mode.short;
                contract.append(short);
            }

            // Coinche et contrée : la valeur du contrat à côté de la couleur (×2, ×4 si coinché)
            if (taking && this.live.contract) {
                const value = document.createElement('span');
                value.className = 'player__contract-value';
                value.textContent = `${contractName(this.live.contract)}${this.live.multiplier > 1 ? ` ×${this.live.multiplier}` : ''}`;
                contract.prepend(value);
            }

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

        this.bidding.classList.toggle('bidding--auction', phase === 'annonces');

        if (phase === 'annonces') {
            this.renderAuction();

            return;
        }

        const bidding = phase === 'encheres-1' || phase === 'encheres-2';
        const visible = bidding && Boolean(turned) && !this.dealing;
        const second = phase === 'encheres-2';

        this.bidding.classList.toggle('bidding--visible', visible);
        this.bidding.classList.toggle('bidding--second', second && visible && turn === this.me && !this.busy);
        this.bidding.classList.toggle('bidding--mine', visible && turn === this.me && !this.busy);
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

    // ---------- Coinche et contrée : enchères ----------

    contractLabel(contract, trump, mode) {
        return `${contractName(contract)} ${SUIT_SIGNS[trump] ?? MODES[mode]?.short ?? ''}`.trim();
    }

    words() {
        return WORDS[this.view.variant] ?? WORDS.coinche;
    }

    // Contrat en cours, vu de tous : « 110 ♥ · Robot Bip · coinché »
    auctionSummary() {
        const { contract, trump, taker, multiplier, mode } = this.live;

        if (!contract) {
            return 'Aucune annonce pour l’instant';
        }

        return [`Contrat ${this.contractLabel(contract, trump, mode)}`, this.player(taker).pseudo, multiplier > 1 ? this.words()[multiplier] : null].filter(Boolean).join(' · ');
    }

    renderAuction() {
        const { turn } = this.live;
        const options = this.view.auction;
        const mine = turn === this.me && !this.busy && Boolean(options);
        const visible = !this.dealing;

        this.bidding.classList.toggle('bidding--visible', visible);
        this.bidding.classList.toggle('bidding--second', false);
        this.bidding.classList.toggle('bidding--mine', visible && mine);
        this.biddingCard.replaceChildren();
        this.biddingActions.replaceChildren();

        if (!visible) {
            return;
        }

        if (!mine) {
            this.biddingTitle.textContent = this.auctionSummary();
            this.biddingText.textContent = turn === this.me ? '' : `${this.player(turn).pseudo} réfléchit…`;

            return;
        }

        this.biddingTitle.textContent = 'À vous d’annoncer';
        this.biddingText.textContent = this.auctionSummary();

        // 1re ligne : le contrat (± par dizaines, jusqu'au capot), passer, coincher ; 2e ligne : une couleur pour annoncer
        const { contracts } = options;
        const top = document.createElement('div');
        top.className = 'bidding__row';
        let value = null;

        if (contracts.length > 0) {
            if (!contracts.includes(this.auctionValue)) {
                [this.auctionValue] = contracts;
            }

            const index = contracts.indexOf(this.auctionValue);
            value = document.createElement('span');
            value.className = 'bidding__value';
            value.textContent = contractName(this.auctionValue);
            value.setAttribute('aria-live', 'polite');
            top.append(
                this.stepButton('−', 'Annonce plus basse', -1, index === 0),
                value,
                this.stepButton('+', 'Annonce plus haute', 1, index === contracts.length - 1),
            );
        }

        top.append(this.bidButton('Passer', null, 'bidding__button--pass'));

        if (options.coinche) {
            top.append(this.actionButton(this.words().coinche, 'coinche'));
        }

        if (options.surcoinche) {
            top.append(this.actionButton(this.words().surcoinche, 'surcoinche'));
        }

        this.biddingActions.append(top);

        if (value) {
            const suits = document.createElement('div');
            suits.className = 'bidding__row';
            SUITS.forEach((suit) => {
                const button = this.bidButton(null, suit, 'bidding__button--suit bidding__button--announce');
                button.setAttribute('aria-label', `Annoncer ${value.textContent} à ${SUIT_LABELS[suit]}`);
                suits.append(button);
            });

            // Coinche : tout atout et sans atout
            if (options.modes) {
                Object.entries(MODES).forEach(([mode, { short, label }]) => {
                    const button = this.bidButton(short, null, 'bidding__button--suit bidding__button--mode bidding__button--announce');
                    button.dataset.suit = mode;
                    button.setAttribute('aria-label', `Annoncer ${value.textContent} en ${label}`);
                    suits.append(button);
                });
            }

            this.biddingActions.append(suits);
        }
    }

    stepButton(text, label, step, disabled) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'bidding__step';
        button.textContent = text;
        button.dataset.step = String(step);
        button.disabled = disabled;
        button.setAttribute('aria-label', label);

        return button;
    }

    actionButton(text, action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'bidding__button bidding__button--take bidding__button--action';
        button.dataset.action = action;
        button.textContent = text;

        return button;
    }

    changeAuctionValue(step) {
        const contracts = this.view.auction?.contracts ?? [];
        const index = Math.min(contracts.length - 1, Math.max(0, contracts.indexOf(this.auctionValue) + step));
        this.auctionValue = contracts[index];
        this.renderBidding();
    }

    async onAuction(event) {
        const words = {
            bid: this.contractLabel(event.contract, event.suit, event.mode),
            pass: 'Passe',
            coinche: this.words().saidCoinche,
            surcoinche: this.words().saidSurcoinche,
        };
        this.say(event.position, words[event.action]);

        if (event.action === 'bid') {
            Object.assign(this.live, { contract: event.contract, trump: event.suit, mode: event.mode, taker: event.position });
        } else if (event.action === 'coinche') {
            Object.assign(this.live, { multiplier: 2, coincher: event.position });
        } else if (event.action === 'surcoinche') {
            this.live.multiplier = 4;
        }

        this.live.turn = (event.position + 1) % 4;
        this.renderPlayers();
        this.renderBidding();
        // Même rythme sur tous les écrans de la table (le sien compris) : tout le monde voit la même chose en même temps
        await wait(BID_PAUSE);
    }

    // Fin des enchères : le contrat est connu, le jeu commence à gauche du donneur
    async onContract(event) {
        Object.assign(this.live, {
            phase: 'jeu', taker: event.taker, trump: event.trump, mode: event.mode, contract: event.contract, multiplier: event.multiplier,
            turn: (this.live.dealer + 1) % 4,
        });
        this.view.auction = null;
        this.render();
        this.showToast(`${this.player(event.taker).pseudo} joue ${this.contractLabel(event.contract, event.trump, event.mode)}${event.multiplier > 1 ? ` ${this.words()[event.multiplier]}` : ''}`, 2200);
        await wait(700);
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
        // La dernière donne reste en vue : les précédentes se lisent en remontant
        const list = this.score.querySelector('.score__list');
        list.scrollTop = list.scrollHeight;
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

        const seconds = this.timerSeconds(phase);
        const total = seconds * 1000;
        const left = Math.min(total, (this.view.secondsLeft ?? seconds) * 1000);

        // Un seul minuteur visible : la jauge du centre quand je dois prendre ou passer, l'anneau de l'avatar sinon
        const gauge = this.usesGauge(phase) && turn === this.me;

        this.timerKey = key;
        this.timerAnimation?.cancel();
        window.clearTimeout(this.timeoutTimer);
        this.seat(this.me).classList.toggle('player--bidding', gauge);

        if (!gauge) {
            this.timerAnimation = this.seat(turn).querySelector('.player__ring').animate([{ '--ring': '100%' }, { '--ring': '0%' }], {
                duration: total,
                fill: 'forwards',
            });
            this.timerAnimation.currentTime = total - left;
        }

        this.renderGauge(gauge, total, left);
        // Le serveur accorde une seconde de marge : on attend un peu plus avant de lui signaler le temps écoulé
        this.timeoutTimer = window.setTimeout(() => this.onTimeout(key), left + 1500);
    }

    // Aux enchères, le temps pour prendre ou passer ; ensuite, le temps pour jouer une carte (le tarot a les siens)
    timerSeconds(phase) {
        return BIDDING_PHASES.includes(phase) ? (this.view.bidSeconds ?? this.view.turnSeconds) : this.view.turnSeconds;
    }

    usesGauge(phase) {
        return BIDDING_PHASES.includes(phase);
    }

    // Jauge des enchères : elle se vide (vert, puis jaune, puis rouge) pendant le temps pour prendre ou passer
    renderGauge(mine, total, left) {
        this.gaugeAnimation?.cancel();

        if (!mine) {
            return;
        }

        this.gaugeAnimation = this.element.querySelector('.bidding__gauge-fill').animate([
            { transform: 'scaleX(1)', backgroundColor: '#3fbf7f' },
            { transform: 'scaleX(0.4)', backgroundColor: '#ffd23f', offset: 0.6 },
            { transform: 'scaleX(0)', backgroundColor: '#ff5a3d' },
        ], { duration: total, fill: 'forwards' });
        this.gaugeAnimation.currentTime = total - left;
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

            // Déjà joué par quelqu'un d'autre, ou pas encore l'heure : on se remet à jour sans rien afficher,
            // et le minuteur repart sur le temps restant donné par le serveur (sinon plus rien ne se passerait)
            this.timerKey = null;
            this.queue = this.queue.then(async () => this.finish(payload.view ?? await this.fetchView() ?? this.view));
        } catch {
            this.busy = false;
            this.timerKey = null;
            this.renderTimer();
        }
    }

    say(position, text) {
        const bubble = this.seat(position).querySelector('.player__bubble');
        bubble.textContent = text;
        bubble.classList.add('player__bubble--visible');
        window.setTimeout(() => bubble.classList.remove('player__bubble--visible'), 1300);
    }

    showToast(text, duration = 1800) {
        this.toast.show(text, duration);
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const card = event.target.closest('.hand__card--playable');
        const bid = event.target.closest('.bidding__button');

        if (card && this.hand.classList.contains('hand--active')) {
            this.playCard(card);
        } else if (event.target.closest('.belote__declare--visible') && !this.busy) {
            this.live.declarable = [];
            this.send('declaration', {});
        } else if (event.target.closest('.bidding__step') && !this.busy) {
            this.changeAuctionValue(Number(event.target.closest('.bidding__step').dataset.step));
        } else if (bid?.classList.contains('bidding__button--announce') && !this.busy) {
            this.send('annonce', { contract: this.auctionValue, suit: bid.dataset.suit });
        } else if (bid?.classList.contains('bidding__button--action') && !this.busy) {
            this.send(bid.dataset.action, {});
        } else if (bid && !this.busy) {
            this.send('encheres', { take: bid.dataset.take === '1', suit: bid.dataset.suit ?? null });
        } else if (event.target.closest('.belote__tool--rules')) {
            this.element.querySelector('.belote__rules').showModal();
        } else if (event.target.closest('.belote__rules-close')) {
            this.element.querySelector('.belote__rules').close();
        } else if (event.target.closest('.belote__quit')) {
            this.leaveDialog.open(this.live.phase === 'terminee');
        } else if (event.target.closest('.belote__leave-stay')) {
            this.leaveDialog.close();
        } else if (event.target.closest('.belote__leave-confirm')) {
            this.leaveDialog.confirm(event.target.closest('.belote__leave-confirm'));
        } else if (event.target.closest('.belote__tool--fullscreen')) {
            toggleFullscreen(this.element.querySelector('.belote__frame'));
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
        this.renderDeclare();

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
        this.renderDeclare();

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

    // Un autre joueur a joué (Mercure) : on récupère sa vue et ce qui s'est passé.
    // Pendant une animation, l'annonce n'est plus ignorée : elle est gardée et récupérée juste après (sinon cet écran
    // prenait du retard sur les autres jusqu'au coup suivant)
    onUpdate(message) {
        const { version } = JSON.parse(message.data);

        this.latestVersion = Math.max(this.latestVersion, version);
        this.catchUp();
    }

    async catchUp() {
        if (this.busy || this.fetching || this.latestVersion <= this.view.version) {
            return;
        }

        this.fetching = true;

        try {
            const response = await fetch(`${this.apiUrl}?depuis=${this.view.version}`, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                const payload = await response.json();

                if (payload.view.version > this.view.version) {
                    this.enqueue(payload.events, payload.view);
                }
            }
        } catch {
            // Réseau coupé : le prochain message relancera la mise à jour
        } finally {
            this.fetching = false;
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
        // Un joueur a quitté la partie : sa place passe à un robot (nom et avatar changent)
        const players = JSON.stringify(view.players);
        const changed = players !== JSON.stringify(this.view.players);
        this.view = view;

        if (changed) {
            this.buildPlayers();
        }

        this.live = structuredClone(view);
        this.busy = false;
        this.pendingCard = null;
        this.render();

        if (view.phase === 'terminee') {
            this.showEnd(view);
        }

        // Une annonce arrivée pendant l'animation : on rattrape tout de suite
        this.catchUp();
    }

    // ---------- Événements (animations) ----------

    async apply(event) {
        const handlers = {
            dealt: () => this.onDealt(event),
            bid: () => this.onBid(event),
            auction: () => this.onAuction(event),
            contract: () => this.onContract(event),
            secondRound: () => this.showToast('Second tour : on peut choisir une autre couleur'),
            redeal: async () => { this.showToast('Personne ne prend : on redistribue'); await wait(900); },
            completed: () => this.onCompleted(event),
            played: () => this.onPlayed(event),
            belote: async () => { this.say(event.position, `${event.word} !`); await wait(500); },
            declared: () => this.onDeclared(event),
            announcements: () => this.onAnnouncements(event),
            trick: () => this.onTrick(event),
            dealEnd: () => this.onDealEnd(event),
            gameEnd: async () => {},
            left: async () => { this.showToast(`${event.pseudo} a quitté la partie : un robot prend sa place`, 2600); await wait(600); },
        };

        await handlers[event.type]?.();
    }

    async onDealt(event) {
        // Coinche et contrée : 8 cartes (3, 3 puis 2) et pas de carte retournée
        const auction = !event.turned;
        Object.assign(this.live, {
            deal: event.deal, dealer: event.dealer, phase: auction ? 'annonces' : 'encheres-1', turned: null, trump: null, taker: null,
            contract: null, multiplier: 1, coincher: null, mode: 'couleur',
            trick: [], hand: [], counts: [0, 0, 0, 0], points: [0, 0, 0, 0], turn: (event.dealer + 1) % 4,
        });
        this.dealing = true;
        this.render();
        await this.dealFlights(event.dealer, auction ? [3, 3, 2] : [3, 2], event.counts, event.cards);

        if (!auction) {
            await this.turnUp(event.turned);
        }

        this.dealing = false;
        this.renderBidding();
        await wait(250);
    }

    // La carte du dessus du paquet est retournée au milieu de la table
    async turnUp(code) {
        this.live.turned = code;
        this.renderBidding();
        const card = this.biddingCard.firstElementChild;
        card.style.visibility = 'hidden';
        await this.animator.fly(this.cardElement(null), this.centerBox(), this.animator.box(card), {
            duration: 460, rotate: [0, 0], reveal: this.cardElement(code),
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

        // Même rythme sur tous les écrans de la table (le sien compris) : tout le monde voit la même chose en même temps
        await wait(BID_PAUSE);
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
            // Sa carte est déjà posée ; les autres écrans la voient arriver pendant ce temps : on attend autant qu'eux
            this.pendingCard = null;
            await wait(PLAY_DURATION);
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
                duration: PLAY_DURATION, rotate: [this.angle(event.position), this.trickTilt(event.position)], reveal: mine ? null : this.cardElement(event.card),
            });
            slot.style.visibility = '';
            this.live.trick = [...this.live.trick, [event.position, event.card]];
        }

        this.live.turn = this.nextPosition(event.position);
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

        if ((result.announces?.[us] ?? 0) > 0) {
            bonus.push(`dont +${result.announces[us]} d’annonces`);
        }

        this.live.scores = scores;
        this.live.dealScores = [...(this.live.dealScores ?? []), result.scored];
        this.renderScore();

        const ourContract = this.player(result.taker).team === us;
        this.result.querySelector('.result__title').textContent = OUTCOMES[result.outcome][ourContract ? 0 : 1];
        this.result.querySelector('.result__taker').textContent = result.contract
            ? `Contrat ${this.contractLabel(result.contract, result.trump, result.mode)} de ${this.player(result.taker).pseudo}${result.multiplier > 1 ? ` (${this.words()[result.multiplier]})` : ''}`
            : `Pris par ${this.player(result.taker).pseudo} à ${SUIT_LABELS[result.trump]}`;
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
        const from = this.centerBox();
        const queue = [...cards];
        const flights = [];
        let delay = 0;

        for (const packet of packets ?? [null]) {
            for (let offset = 1; offset <= this.view.players.length; offset += 1) {
                const position = (dealer + offset) % this.view.players.length;
                const count = packet ?? counts[position];
                const to = this.seatBox(position);
                const mine = position === this.me;

                for (let i = 0; i < count; i += 1) {
                    const code = mine ? queue.shift() : null;
                    const flight = this.animator.fly(this.cardElement(null), from, to, {
                        duration: 380, delay, rotate: [0, this.angle(position) + (i - 1) * 3], reveal: code ? this.cardElement(code) : null,
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
            await this.turnUp(turned);
        }

        this.dealing = false;
        this.busy = false;
        this.render();
    }
}
