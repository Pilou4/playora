// Table d'un duel solo (solitaire, Spider), de 2 à 5 joueurs, chacun à son rythme. Le serveur décide de tout : la page envoie
// « je pose telle carte là », puis affiche la grille renvoyée par le serveur, qui fait foi. Les cartes glissent
// de leur ancienne place à la nouvelle (rien ne saute), et la page ne connaît les règles que par la liste
// des places permises (« targets »).
// Ici, tout ce qui est commun : temps, score, résultat final, glisser-déposer, échanges avec le serveur.
// Pendant sa partie, le joueur ne voit que son score ; les scores des autres n'apparaissent qu'une fois sa partie finie.
// Chaque jeu (SolitaireTable, SpiderTable) dessine sa grille et dit où une carte peut être lâchée.

import { BackGuard, LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';
import { SoundButton } from '../Sound.js';

const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const RANK_LABELS = { as: 'As', valet: 'Valet', dame: 'Dame', roi: 'Roi' };
// Décalage vertical des cartes dans une pile, en largeurs de carte : cartes cachées, cartes visibles
const HIDDEN_STEP = 0.12;
const VISIBLE_STEP = 0.3;
// Au-delà, un mouvement du pointeur devient un glisser (en dessous, c'est un simple clic)
const DRAG_THRESHOLD = 6;
const MOVE_DURATION = 240;
// Double-clic ou clavier : la carte file à sa place
const PLACE_DURATION = 140;
// Lâchée au-dessus d'une place : la carte s'y cale en un éclair, son ombre de carte « en main » s'efface en se posant
const DROP_DURATION = 110;
// Résultat affiché, d'autres joueurs encore en jeu : vérification des grilles dont le temps est écoulé
const WAITING_CHECK = 60000;
// Résultat final : « Continuer » mène tout seul au tableau des résultats (« Mes duels ») au bout de ce délai
const CONTINUE_SECONDS = 30;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');
// Cartes cachées à l'arrivée tant que leurs images ne sont pas prêtes (au plus ce délai)
const IMAGES_WAIT = 1500;
const PRELOAD_SUITS = ['pique', 'coeur', 'carreau', 'trefle'];
const PRELOAD_RANKS = ['as', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'valet', 'dame', 'roi'];

export const formatScore = (value) => new Intl.NumberFormat('fr-FR').format(value);

const formatClock = (seconds) => {
    const whole = Math.max(0, Math.ceil(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
};

export default class PatienceTable {
    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.cardsUrl = dataset.cardsUrl;
        this.backUrl = dataset.backUrl;
        this.view = JSON.parse(dataset.view);

        this.felt = element.querySelector('.solitaire__felt');
        this.columns = [...element.querySelectorAll('.solitaire__column')];
        this.dragLayer = element.querySelector('.solitaire__drag');
        this.overlay = element.querySelector('.solitaire__overlay');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.solitaire__leave-dialog'), leaveUrl: dataset.leaveUrl, lobbyUrl: dataset.lobbyUrl, csrf: this.csrf, replace: true });
        this.clockFill = element.querySelector('.solitaire__clock-fill');
        this.clockText = element.querySelector('.solitaire__clock-text');
        this.scoreValue = element.querySelector('.solitaire__score-value');
        this.undoButton = element.querySelector('.solitaire__undo');
        // « Arrêter » n'existe plus (« Quitter » suffit) ; gardé pour une table qui l'aurait encore
        this.stopButton = element.querySelector('.solitaire__stop');

        // Une longue pile se resserre au plus jusqu'à cette part de son écart normal
        this.minSqueeze = 0.35;
        // Éléments des cartes, gardés d'un affichage à l'autre (cardElement)
        this.cards = new Map();
        this.backs = [];
        this.backIndex = 0;
        this.busy = false;
        // Coups joués pendant qu'un autre part au serveur : envoyés à la suite, aucun clic n'est perdu
        this.queue = [];
        // Le coup dont la réponse est en train d'être affichée (« talon », « deplacements »…)
        this.action = null;
        // Réponse arrivée pendant un glisser : affichée quand la carte est lâchée
        this.pendingView = null;
        this.drag = null;
        // Cartes lâchées après un glisser : elles partent de là où le pointeur les a laissées
        this.dropPositions = null;
        this.deadline = 0;
        this.timeoutSent = false;
        this.clockTimer = null;
        this.phaseTimer = null;
        this.continueTimer = null;

        this.onClick = this.onClick.bind(this);
        this.onPointerDown = this.onPointerDown.bind(this);
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.onUpdate = this.onUpdate.bind(this);
        this.onResize = this.onResize.bind(this);
        this.tick = this.tick.bind(this);
    }

    start() {
        this.element.addEventListener('click', this.onClick);
        this.felt.addEventListener('pointerdown', this.onPointerDown);
        window.addEventListener('resize', this.onResize);
        // « Précédent » du navigateur : la partie est quittée (ses points comptent), sans retour possible
        new BackGuard({ leaveUrl: this.element.dataset.leaveUrl, exitUrl: this.element.dataset.lobbyUrl, csrf: this.csrf, isOver: () => !this.playing && this.view.phase !== 'intro' }).start(this.element);
        // Bouton du son : coupé ou remis, gardé dans ce navigateur
        SoundButton.start(this.element);

        // Partie déjà finie (retour par l'historique, lien…) : on va directement à son résultat
        if (this.view.phase === 'attente' || this.view.phase === 'terminee') {
            window.location.replace(this.element.dataset.lobbyUrl);

            return;
        }

        this.onResize();
        // Les cartes n'apparaissent qu'avec leurs images : jamais de cartes blanches à l'arrivée
        this.felt.classList.add('solitaire__felt--loading');
        this.render(this.view, false);
        this.revealWhenReady();

        if (this.hubUrl && 'EventSource' in window) {
            new EventSource(this.hubUrl).addEventListener('message', this.onUpdate);
        }
    }

    revealWhenReady() {
        const images = [...this.felt.querySelectorAll('img')].map((image) => image.decode().catch(() => {}));
        const timeout = new Promise((resolve) => {
            window.setTimeout(resolve, IMAGES_WAIT);
        });

        Promise.race([Promise.all(images), timeout]).then(() => {
            this.felt.classList.remove('solitaire__felt--loading');
            this.preloadFaces();
            this.revealed();
        });
    }

    // La table vient d'apparaître : chaque jeu peut y jouer une animation (cartes de la donne qui montent…)
    revealed() {}

    // Les autres cartes du jeu se chargent en arrière-plan : une carte piochée s'affiche aussitôt, sans blanc
    preloadFaces() {
        this.preloaded = PRELOAD_SUITS.flatMap((suit) => PRELOAD_RANKS.map((rank) => {
            const image = new Image();
            image.src = `${this.cardsUrl}${suit}/${rank}.svg`;

            return image;
        }));
    }

    // La table occupe tout l'écran sous l'en-tête du site ; les piles se resserrent selon la hauteur
    onResize() {
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        this.element.style.setProperty('--offset', `${Math.round(top)}px`);
        // Écart entre les cartes d'une pile, réglé par le CSS (plus grand sur téléphone, où la table est haute)
        this.fan = Number.parseFloat(window.getComputedStyle(this.felt).getPropertyValue('--fan')) || 1;
        this.layoutColumns();
    }

    // ---------- À écrire par chaque jeu ----------

    // Dessine la grille du joueur (talon, piles…) d'après this.board
    renderBoard() {}

    // Clic propre au jeu (talon, carte) : renvoie true s'il a été traité
    handleClick() {
        return false;
    }

    // La place (« colonne-3 », « famille »…) sous le pointeur, parmi les éléments survolés
    placeUnder() {
        return null;
    }

    // Envoie le coup « cette carte va là »
    move() {}

    // ---------- Affichage ----------

    get board() {
        return this.view.board;
    }

    get playing() {
        return this.view.phase === 'jeu';
    }

    // Nouvelle vue du serveur : on note où était chaque carte, on redessine, puis chaque carte glisse jusqu'à sa nouvelle place
    render(view, animate = true) {
        const before = animate ? this.positions() : new Map();

        this.view = view;
        this.deadline = performance.now() + view.secondsLeft * 1000;
        this.timeoutSent = false;
        this.backIndex = 0;
        this.renderBoard();
        this.renderHeader();
        this.renderOverlay();
        this.planPhase();

        if (animate) {
            this.slide(before);
        }

        this.dragLayer.replaceChildren();
        this.dragLayer.classList.remove('solitaire__drag--active');
    }

    positions() {
        const positions = new Map();

        this.felt.querySelectorAll('.solitaire__card[data-card]').forEach((card) => {
            positions.set(card.dataset.card, card.getBoundingClientRect());
        });

        this.dropPositions?.forEach((rect, code) => positions.set(code, rect));
        this.dropPositions = null;

        return positions;
    }

    slide(before) {
        if (REDUCED.matches) {
            return;
        }

        this.felt.querySelectorAll('.solitaire__card[data-card]').forEach((card) => {
            const from = before.get(card.dataset.card);

            if (!from) {
                this.enter(card);

                return;
            }

            const to = card.getBoundingClientRect();
            const dx = from.left - to.left;
            const dy = from.top - to.top;

            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                card.classList.add('solitaire__card--moving');
                card.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
                    { duration: MOVE_DURATION, easing: 'cubic-bezier(.2, .8, .2, 1)' },
                ).finished.then(() => card.classList.remove('solitaire__card--moving'), () => {});
            }
        });
    }

    // Carte qui apparaît (retournée dans une pile, piochée…) : elle apparaît en douceur ; chaque jeu peut faire mieux
    enter(card) {
        card.animate([{ opacity: 0.35 }, { opacity: 1 }], { duration: 180, easing: 'ease-out' });
    }

    // Une carte : son code commence par la couleur et la hauteur (« coeur-roi », « coeur-roi-2 » au Spider) ; null pour un dos
    // La même carte reprend le même élément d'un affichage à l'autre (image déjà là : rien ne clignote) ;
    // les dos viennent d'une petite réserve, remise à zéro à chaque affichage
    cardElement(code, movable) {
        if (code === null) {
            const back = this.backs[this.backIndex] ?? this.freshCard(null, false);

            this.backs[this.backIndex] = back;
            this.backIndex += 1;
            this.resetCard(back, 'card solitaire__card card--back');

            return back;
        }

        const key = `${code}|${movable ? 1 : 0}`;
        const card = this.cards.get(key) ?? this.freshCard(code, movable);

        this.cards.set(key, card);
        this.resetCard(card, `card solitaire__card${movable ? ' solitaire__card--movable' : ''}`);

        return card;
    }

    // Un élément repris : ni animation en cours, ni place, ni état d'un glisser précédent
    resetCard(card, className) {
        card.getAnimations().forEach((animation) => animation.cancel());
        card.className = className;
        card.style.removeProperty('--y');
        card.style.removeProperty('--z');
        card.style.removeProperty('--i');
    }

    // Un élément neuf (une carte qui n'est pas une carte du jeu en place : talon montré, famille rangée…)
    freshCard(code, movable) {
        const card = document.createElement(movable ? 'button' : 'span');
        const face = document.createElement('img');

        card.className = 'card solitaire__card';
        face.className = 'card__face';
        face.draggable = false;

        if (code) {
            const [suit, rank] = code.split('-');
            card.dataset.card = code;
            face.src = `${this.cardsUrl}${suit}/${rank}.svg`;
            face.alt = `${RANK_LABELS[rank] ?? rank} de ${SUIT_LABELS[suit]}`;
        } else {
            card.classList.add('card--back');
            face.src = this.backUrl;
            face.alt = '';
        }

        if (movable) {
            card.type = 'button';
            card.classList.add('solitaire__card--movable');
        }

        card.append(face);

        return card;
    }

    isMovable(code) {
        return this.playing && code !== null && Object.hasOwn(this.board.targets, code);
    }

    // Les piles : chacune reçoit ses cartes (null : face cachée)
    renderColumns(codes) {
        codes.forEach((cards, index) => {
            this.columns[index].replaceChildren(...cards.map((code) => this.cardElement(code, this.isMovable(code))));
        });

        this.layoutColumns();
    }

    // Les cartes d'une pile se chevauchent ; une longue pile se resserre pour tenir dans la table
    layoutColumns() {
        this.columns.forEach((column) => {
            const cards = [...column.children];
            const width = cards[0]?.getBoundingClientRect().width ?? 0;

            if (width === 0) {
                return;
            }

            const steps = cards.map((card) => (card.classList.contains('card--back') ? HIDDEN_STEP : VISIBLE_STEP) * width * (this.fan ?? 1));
            const wanted = steps.slice(0, -1).reduce((sum, step) => sum + step, 0);
            const room = column.getBoundingClientRect().height - width * 1.4;
            const scale = wanted > room && wanted > 0 ? Math.max(this.minSqueeze, room / wanted) : 1;
            let y = 0;

            cards.forEach((card, row) => {
                card.style.setProperty('--y', `${Math.round(y)}px`);
                card.style.setProperty('--z', String(row + 1));
                y += steps[row] * scale;
            });
        });
    }

    renderHeader() {
        const { board } = this;

        this.scoreValue.textContent = formatScore(board.score);
        this.undoButton.disabled = !this.playing || !board.canUndo;
        this.stopButton?.toggleAttribute('disabled', !this.playing);
        this.tick();
    }

    // Une fois sa partie finie : le résultat final, puis les autres joueurs
    renderOverlay() {
        const { phase, board } = this.view;
        const title = this.overlay.querySelector('.solitaire__panel-title');
        const text = this.overlay.querySelector('.solitaire__panel-text');
        const details = this.overlay.querySelector('.solitaire__details');
        const total = this.overlay.querySelector('.solitaire__total');
        const link = this.overlay.querySelector('.solitaire__panel-link');
        const over = phase === 'attente' || phase === 'terminee';

        this.overlay.classList.toggle('solitaire__overlay--visible', over);
        this.overlay.classList.toggle('solitaire__overlay--result', over);
        link.classList.toggle('solitaire__panel-link--visible', over);
        details.replaceChildren(...(over ? this.detailRows(board.details ?? []) : []));
        total.textContent = over ? `${formatScore(board.score)} points` : '';
        // Seulement son propre score : le classement du duel est sur la page suivante (« Continuer »)

        if (over) {
            title.textContent = 'Résultat final';
            text.textContent = this.resultText();
            this.startContinue(link);
        }
    }

    // « Continuer (30 s) » : le compte à rebours ne part qu'une fois, puis le tableau des résultats s'ouvre
    startContinue(link) {
        if (this.continueTimer !== null) {
            return;
        }

        const end = performance.now() + CONTINUE_SECONDS * 1000;
        const update = () => {
            const left = Math.max(0, Math.ceil((end - performance.now()) / 1000));
            link.textContent = `Continuer (${left} s)`;

            if (left === 0) {
                window.clearInterval(this.continueTimer);
                window.location.replace(link.href);
            }
        };

        update();
        this.continueTimer = window.setInterval(update, 500);
    }

    resultText() {
        return this.board.complete ? 'Jeu résolu !' : 'Votre partie est terminée.';
    }

    // Le détail du score (cartes rangées, temps restant, retours…)
    detailRows(details) {
        return details.flatMap(({ label, value }) => {
            const term = document.createElement('dt');
            const definition = document.createElement('dd');

            term.className = 'solitaire__detail-label';
            term.textContent = label;
            definition.className = 'solitaire__detail-value';
            definition.textContent = value;

            return [term, definition];
        });
    }

    // ---------- Temps ----------

    tick() {
        window.clearTimeout(this.clockTimer);
        const left = this.playing ? Math.max(0, (this.deadline - performance.now()) / 1000) : this.view.secondsLeft;
        const ratio = left / this.view.seconds;

        this.clockFill.style.setProperty('--left', ratio.toFixed(4));
        this.clockText.textContent = formatClock(left);
        this.clockFill.classList.toggle('solitaire__clock-fill--late', this.playing && left <= 30);

        if (!this.playing) {
            return;
        }

        // Temps écoulé : on laisse au serveur une seconde de marge avant de lui demander de fermer la grille
        if (left <= 0) {
            this.undoButton.disabled = true;
            this.stopButton?.setAttribute('disabled', '');
            this.clockTimer = window.setTimeout(() => this.sendTimeout(), 1200);

            return;
        }

        this.clockTimer = window.setTimeout(this.tick, 250);
    }

    // Partie finie, d'autres joueurs encore en jeu : de temps en temps, les grilles dont le temps est écoulé sont closes
    planPhase() {
        window.clearTimeout(this.phaseTimer);

        if (this.view.phase === 'attente') {
            // Un autre joueur a peut-être fermé sa page : de temps en temps, le serveur ferme les grilles dont le temps est écoulé
            this.phaseTimer = window.setTimeout(() => this.sendTimeout(), WAITING_CHECK);
        }
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
        if (this.handleClick(event)) {
            return;
        }

        if (event.target.closest('.solitaire__undo')) {
            this.send('retour', {});
        } else if (event.target.closest('.solitaire__stop')) {
            this.element.querySelector('.solitaire__stop-dialog').showModal();
        } else if (event.target.closest('.solitaire__stop-cancel')) {
            this.element.querySelector('.solitaire__stop-dialog').close();
        } else if (event.target.closest('.solitaire__stop-confirm')) {
            this.element.querySelector('.solitaire__stop-dialog').close();
            this.send('arret', {});
        } else if (event.target.closest('.belote__tool--rules')) {
            this.element.querySelector('.belote__rules').showModal();
        } else if (event.target.closest('.belote__rules-close')) {
            this.element.querySelector('.belote__rules').close();
        } else if (event.target.closest('.belote__quit')) {
            // Ma partie est finie (j'attends les autres, ou le duel est terminé) : on part sans rien demander
            this.leaveDialog.open(!this.playing);
        } else if (event.target.closest('.solitaire__leave-cancel')) {
            this.leaveDialog.close();
        } else if (event.target.closest('.solitaire__leave-confirm')) {
            this.leaveDialog.confirm(event.target.closest('.solitaire__leave-confirm'));
        } else if (event.target.closest('.belote__tool--fullscreen')) {
            toggleFullscreen(this.element.querySelector('.belote__frame'));
        }
    }

    // Une carte qu'on peut jouer d'un simple clic (pas à la fin d'un glisser)
    clickedCard(event) {
        const card = event.target.closest('.solitaire__card--movable');

        return card && this.playing && !this.drag?.moved ? card : null;
    }

    // ---------- Glisser-déposer ----------

    onPointerDown(event) {
        const card = event.target.closest('.solitaire__card--movable');

        if (!card || !this.playing || event.button > 0) {
            return;
        }

        const rect = card.getBoundingClientRect();
        this.drag = { card, x: event.clientX, y: event.clientY, dx: event.clientX - rect.left, dy: event.clientY - rect.top, moved: false, pointer: event.pointerId };
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerUp);
    }

    onPointerMove(event) {
        const { drag } = this;

        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }

        if (!drag.moved) {
            if (Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < DRAG_THRESHOLD) {
                return;
            }

            // La grille a été redessinée depuis l'appui : on reprend la carte à sa nouvelle place
            if (!drag.card.isConnected) {
                drag.card = this.felt.querySelector(`.solitaire__card--movable[data-card="${drag.card.dataset.card}"]`);

                if (!drag.card) {
                    this.drag = null;

                    return;
                }
            }

            this.liftCards(drag);
        }

        const felt = this.felt.getBoundingClientRect();
        this.dragLayer.style.setProperty('--x', `${Math.round(event.clientX - felt.left - drag.dx)}px`);
        this.dragLayer.style.setProperty('--y', `${Math.round(event.clientY - felt.top - drag.dy)}px`);
    }

    // La carte et celles posées dessus (dans une pile) quittent leur place et suivent le pointeur
    liftCards(drag) {
        const siblings = [...drag.card.parentElement.children];
        const fromColumn = drag.card.parentElement.classList.contains('solitaire__column');
        const block = fromColumn ? siblings.slice(siblings.indexOf(drag.card)) : [drag.card];
        const top = drag.card.getBoundingClientRect().top;

        drag.moved = true;
        this.dragLayer.replaceChildren(...block.map((card) => {
            const copy = card.cloneNode(true);
            copy.classList.remove('solitaire__card--movable');
            copy.style.setProperty('--y', `${Math.round(card.getBoundingClientRect().top - top)}px`);

            return copy;
        }));
        block.forEach((card) => card.classList.add('solitaire__card--lifted'));
        this.dragLayer.classList.add('solitaire__drag--active');
        this.element.classList.add('solitaire--dragging');
    }

    // Où la carte est lâchée : la place permise que la carte couvre le plus (comme les vrais jeux, pas besoin
    // d'être précis) ; sinon celle sous le pointeur
    dropTarget(event) {
        const code = this.drag.card.dataset.card;
        const allowed = this.board.targets[code] ?? [];
        const dragged = this.dragLayer.querySelector('.solitaire__card')?.getBoundingClientRect();
        let best = null;
        let bestArea = 0;

        allowed.forEach((place) => {
            const box = this.placeContainer(code, place)?.getBoundingClientRect();

            if (!box || !dragged) {
                return;
            }

            const width = Math.min(box.right, dragged.right) - Math.max(box.left, dragged.left);
            const height = Math.min(box.bottom, dragged.bottom) - Math.max(box.top, dragged.top);
            const area = width > 0 && height > 0 ? width * height : 0;

            if (area > bestArea) {
                best = place;
                bestArea = area;
            }
        });

        if (best) {
            return best;
        }

        const place = this.placeUnder(document.elementsFromPoint(event.clientX, event.clientY));

        return allowed.includes(place) ? place : null;
    }

    // L'élément d'une place (« colonne-3 »…) : où la carte se pose. Chaque jeu ajoute ses autres places
    placeContainer(code, place) {
        return place.startsWith('colonne-') ? this.columns[Number(place.replace('colonne-', ''))] ?? null : null;
    }

    // La carte (et celles posées dessus) va tout de suite à la place choisie, que le serveur a déjà indiquée comme
    // permise ; sa réponse, qui fait foi, arrive derrière (carte retournée, score…). Coup refusé : la réponse remet tout
    // en place. Lâchée au-dessus de la bonne place, elle s'y pose net ; au clavier ou au double-clic, elle y file.
    place(code, to) {
        const card = [...this.felt.querySelectorAll('.solitaire__card[data-card]')]
            .find((element) => element.dataset.card === code && !this.dragLayer.contains(element));
        const target = this.placeContainer(code, to);

        // Rien à déplacer ici : les cartes en main repartent à leur place, la réponse du serveur fera foi
        if (!card || !target) {
            this.render(this.view);

            return;
        }

        const siblings = [...card.parentElement.children];
        const block = card.parentElement.classList.contains('solitaire__column') ? siblings.slice(siblings.indexOf(card)) : [card];
        // Lâchées : les cartes partent de là où le joueur les a lâchées (et non de leur ancienne place)
        const drop = this.dropPositions;
        const held = this.dragLayer.querySelector('.solitaire__card');
        const heldShadow = held ? window.getComputedStyle(held).boxShadow : 'none';
        const from = new Map(block.map((item) => [item, drop?.get(item.dataset.card) ?? item.getBoundingClientRect()]));

        this.dropPositions = null;
        block.forEach((item) => {
            item.getAnimations().forEach((animation) => animation.cancel());
            item.classList.remove('solitaire__card--lifted', 'solitaire__card--movable');
            item.style.removeProperty('--i');
            target.append(item);
        });
        this.layoutColumns();
        this.dragLayer.replaceChildren();
        this.dragLayer.classList.remove('solitaire__drag--active');

        if (REDUCED.matches) {
            return;
        }

        block.forEach((item) => {
            const start = from.get(item);
            const end = item.getBoundingClientRect();
            const dx = start.left - end.left;
            const dy = start.top - end.top;

            if (Math.abs(dx) < 1 && Math.abs(dy) < 1) {
                return;
            }

            const rest = window.getComputedStyle(item).boxShadow;
            const frames = drop
                ? [{ transform: `translate(${dx}px, ${dy}px)`, boxShadow: heldShadow }, { transform: 'translate(0, 0)', boxShadow: rest }]
                : [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }];

            item.classList.add('solitaire__card--moving');
            item.animate(frames, { duration: drop ? DROP_DURATION : PLACE_DURATION, easing: 'cubic-bezier(.2, .8, .2, 1)' })
                .finished.then(() => item.classList.remove('solitaire__card--moving'), () => {});
        });
    }

    // La pile de cartes survolée (« colonne-N »)
    columnUnder(under) {
        const column = under.find((element) => element.classList.contains('solitaire__column'));

        return column ? `colonne-${column.dataset.column}` : null;
    }

    onPointerUp(event) {
        const { drag } = this;

        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }

        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerUp);

        if (!drag.moved) {
            this.drag = null;
            this.showPending();

            return;
        }

        // Une réponse est arrivée pendant le glisser : la carte se lâche d'après la grille la plus récente
        if (this.pendingView) {
            this.view = this.pendingView;
            this.pendingView = null;
        }

        const place = event.type === 'pointerup' ? this.dropTarget(event) : null;
        this.element.classList.remove('solitaire--dragging');
        this.dropPositions = new Map([...this.dragLayer.querySelectorAll('.solitaire__card[data-card]')].map((card) => [card.dataset.card, card.getBoundingClientRect()]));

        if (place) {
            // Les cartes restent sous le pointeur jusqu'à la réponse du serveur, puis glissent à leur place
            this.move(drag.card.dataset.card, place);
        } else {
            // Lâchée ailleurs : les cartes repartent à leur place
            this.render(this.view);
        }

        // Le clic qui suit le relâchement ne doit pas rejouer la carte
        window.setTimeout(() => {
            this.drag = null;
        }, 0);
    }

    // ---------- Échanges avec le serveur ----------

    // Le coup part au serveur ; s'il y en a déjà un en route, il attend son tour (rien n'est bloqué ni perdu)
    send(action, body, quiet = false) {
        this.queue.push({ action, body, quiet });

        if (!this.busy) {
            this.flush();
        }
    }

    async flush() {
        this.busy = true;

        while (this.queue.length > 0) {
            // Coup refusé ou souci : les coups suivants, prévus sur l'ancienne grille, sont abandonnés
            if (!(await this.request(this.queue.shift()))) {
                this.queue = [];
            }
        }

        this.busy = false;
    }

    async request({ action, body, quiet }) {
        try {
            const response = await fetch(`${this.apiUrl}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.ok) {
                this.show(payload.view, action);

                return true;
            }

            // Coup refusé par les règles (409, 422) : le message du serveur, en français, est pour le joueur.
            // Toute autre erreur reste technique : jamais affichée telle quelle, la table se remet à jour.
            const refused = response.status === 409 || response.status === 422;

            if (!quiet) {
                this.showToast(refused ? payload.detail ?? 'Coup refusé.' : 'Un souci est survenu : la partie reprend.');
            }

            if (payload.view) {
                this.show(payload.view, action);
            } else {
                await this.refresh();
            }
        } catch {
            this.showToast('Connexion perdue : la partie reprend.');
            await this.refresh();
        }

        return false;
    }

    // Affiche la grille renvoyée ; pendant un glisser, elle attend que la carte soit lâchée
    show(view, action = null) {
        if (this.drag?.moved) {
            this.pendingView = view;

            return;
        }

        this.action = action;
        this.render(view);
        this.action = null;
    }

    showPending() {
        if (this.pendingView) {
            const view = this.pendingView;
            this.pendingView = null;
            this.render(view);
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                this.show((await response.json()).view);
            }
        } catch {
            // Réseau coupé : le prochain message relancera la mise à jour
        }
    }

    // Un autre joueur a fini sa grille, ou la partie commence (Mercure)
    onUpdate(message) {
        const { version } = JSON.parse(message.data);

        if (!this.busy && !this.drag && version > this.view.version) {
            this.refresh();
        }
    }

    showToast(text, duration = 2000) {
        this.toast.show(text, duration);
    }
}
