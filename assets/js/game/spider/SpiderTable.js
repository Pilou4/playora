// Spider (débutant, Spider, Pro) : dix piles de cartes, et dans la barre du bas les familles rangées,
// la ou les piles du Joker et les cinq piles talon.
// Le reste de la table (temps, joueurs, glisser-déposer, échanges avec le serveur) vient de PatienceTable.

import PatienceTable from '../patience/PatienceTable.js';

const RANKS = ['as', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'valet', 'dame', 'roi'];
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');
// Distribution : chaque carte part de la pile talon vers sa pile, l'une après l'autre
const DEAL_DURATION = 240;
const DEAL_STAGGER = 45;
// Famille complète : ses 13 cartes partent une à une (de l'As au Roi) vers sa place dans la barre
const FAMILY_DURATION = 260;
const FAMILY_STAGGER = 45;
const EMPTY_COLUMN = 'Impossible de distribuer : une pile est vide. Posez au moins une carte sur chaque pile.';

export default class SpiderTable extends PatienceTable {
    static init(root = document) {
        root.querySelectorAll('.spider').forEach((element) => new SpiderTable(element).start());
    }

    constructor(element) {
        super(element);

        this.foundations = [...element.querySelectorAll('.spider__foundation')];
        this.jokers = [...element.querySelectorAll('.spider__joker')];
        this.piles = [...element.querySelectorAll('.spider__pile')];
        this.peek = element.querySelector('.spider__peek');
        // 104 cartes : les piles peuvent devenir très longues
        this.minSqueeze = 0.18;

        // Distribution en cours : d'où partent les cartes ; famille complète : les cartes qui s'en vont
        this.dealPile = null;
        this.dealFrom = null;

        this.onPeek = this.onPeek.bind(this);
        this.onPeekEnd = this.onPeekEnd.bind(this);
    }

    start() {
        this.piles.forEach((pile) => {
            pile.addEventListener('pointerenter', this.onPeek);
            pile.addEventListener('focus', this.onPeek);
            pile.addEventListener('pointerleave', this.onPeekEnd);
            pile.addEventListener('blur', this.onPeekEnd);
        });
        super.start();
    }

    // ---------- Affichage ----------

    render(view, animate = true) {
        const leaving = animate && !REDUCED.matches && view.board.foundations.length > (this.board.foundations?.length ?? 0) ? this.snapshot() : null;
        const slot = this.board.foundations?.length ?? 0;

        this.dealFrom = animate && this.action === 'talon' && this.dealPile !== null ? this.piles[this.dealPile]?.getBoundingClientRect() ?? null : null;
        super.render(view, animate);
        this.dealFrom = null;
        this.dealPile = null;

        if (leaving) {
            this.flyFamily(leaving, slot);
        }
    }

    // Les cartes des piles, avant le coup : leur image et leur place
    snapshot() {
        const cards = new Map();

        this.columns.forEach((column) => {
            [...column.querySelectorAll('.solitaire__card[data-card]')].forEach((card) => {
                cards.set(card.dataset.card, { card, rect: this.dropPositions?.get(card.dataset.card) ?? card.getBoundingClientRect() });
            });
        });

        return cards;
    }

    // Famille complète : ses cartes quittent la pile une à une, de l'As au Roi, et filent vers sa place dans la barre
    flyFamily(before, index) {
        const slot = this.foundations[index];
        const present = new Set([...this.felt.querySelectorAll('.solitaire__card[data-card]')].map((card) => card.dataset.card));
        const gone = [...before.entries()].filter(([code]) => !present.has(code))
            .sort(([a], [b]) => RANKS.indexOf(a.split('-')[1]) - RANKS.indexOf(b.split('-')[1]));

        if (!slot || gone.length === 0) {
            return;
        }

        const target = slot.getBoundingClientRect();
        slot.classList.add('spider__foundation--filling');

        gone.forEach(([, { card, rect }], order) => {
            const flight = card.cloneNode(true);

            flight.className = 'card solitaire__card spider__flight';
            flight.style.setProperty('--x', `${rect.left}px`);
            flight.style.setProperty('--y', `${rect.top}px`);
            flight.style.setProperty('--w', `${rect.width}px`);
            this.element.append(flight);

            const last = order === gone.length - 1;

            flight.animate(
                [
                    { transform: 'translate(0, 0) scale(1)' },
                    { transform: `translate(${target.left - rect.left}px, ${target.top - rect.top}px) scale(${target.width / rect.width})` },
                ],
                { duration: FAMILY_DURATION, delay: order * FAMILY_STAGGER, easing: 'cubic-bezier(.3, .7, .25, 1)', fill: 'both' },
            ).finished.then(() => {
                flight.remove();

                if (last) {
                    slot.classList.remove('spider__foundation--filling');
                }
            }, () => flight.remove());
        });
    }

    // Distribution : les nouvelles cartes du bas de chaque pile partent de la pile talon, l'une après l'autre
    enter(card) {
        const column = card.parentElement;

        if (this.dealFrom === null || !column?.classList.contains('solitaire__column') || column.lastElementChild !== card) {
            super.enter(card);

            return;
        }

        const to = card.getBoundingClientRect();

        card.classList.add('solitaire__card--moving');
        card.animate(
            [{ transform: `translate(${this.dealFrom.left - to.left}px, ${this.dealFrom.top - to.top}px)` }, { transform: 'translate(0, 0)' }],
            { duration: DEAL_DURATION, delay: Number(column.dataset.column) * DEAL_STAGGER, easing: 'cubic-bezier(.2, .8, .2, 1)', fill: 'backwards' },
        ).finished.then(() => card.classList.remove('solitaire__card--moving'), () => {});
    }

    renderBoard() {
        this.renderColumns(this.board.columns);
        this.renderFoundations();
        this.renderJokers();
        this.renderStock();
    }

    // Une famille rangée : son Roi
    renderFoundations() {
        this.foundations.forEach((slot, index) => {
            const suit = this.board.foundations[index];
            slot.replaceChildren(...(suit ? [this.kingOf(suit)] : []));
        });
    }

    kingOf(suit) {
        const card = this.faceOnly(`${suit}-roi`);
        card.querySelector('.card__face').alt = 'Famille rangée';

        return card;
    }

    renderJokers() {
        this.jokers.forEach((slot, index) => {
            const code = this.board.jokers[index];
            slot.replaceChildren(...(code ? [this.cardElement(code, this.isMovable(code))] : []));
        });
    }

    // Chaque pile talon : un dos, et sa première carte visible (au Pro, la pile entière se regarde au survol)
    renderStock() {
        const { canDeal } = this.board;

        this.piles.forEach((pile, index) => {
            const { count, shown } = this.board.stock[index];
            const children = [];

            if (count > 0) {
                const back = this.cardElement(null, false);
                const top = this.faceOnly(shown[0]);

                back.classList.add('spider__pile-back');
                top.classList.add('spider__pile-top');
                children.push(back, top);
            }

            pile.replaceChildren(...children);
            // Une pile vide quelque part : la pile talon reste cliquable, pour dire pourquoi on ne peut pas distribuer
            pile.disabled = !this.playing || count === 0;
            pile.classList.toggle('spider__pile--blocked', !canDeal);
            pile.classList.toggle('spider__pile--empty', count === 0);
            pile.setAttribute('aria-label', count === 0 ? 'Pile talon vide' : `Distribuer cette pile talon (${count} cartes)`);
        });

        this.onPeekEnd();
    }

    // Une face sans code de carte : elle ne glisse pas comme une carte du jeu (elle est encore dans le talon)
    faceOnly(code) {
        const card = this.cardElement(code, false);
        delete card.dataset.card;

        return card;
    }

    // ---------- Pro : regarder une pile talon avant de choisir ----------

    onPeek(event) {
        const pile = this.board.stock[Number(event.currentTarget.dataset.pile)];

        if (!pile || pile.shown.length < 2 || !this.playing) {
            return;
        }

        this.peek.replaceChildren(...pile.shown.map((code) => this.faceOnly(code)));
        this.peek.style.setProperty('--pile', event.currentTarget.dataset.pile);
        this.peek.classList.add('spider__peek--visible');
    }

    onPeekEnd() {
        this.peek.classList.remove('spider__peek--visible');
    }

    // ---------- Actions du joueur ----------

    handleClick(event) {
        const pile = event.target.closest('.spider__pile');
        const card = this.clickedCard(event);

        if (pile && this.playing) {
            if (!this.board.canDeal) {
                this.showToast(EMPTY_COLUMN, 2600);

                return true;
            }

            this.dealPile = Number(pile.dataset.pile);
            this.send('talon', { pile: this.dealPile });

            return true;
        }

        // Comme les vrais jeux : à la souris, on glisse les cartes (un clic ne les déplace pas).
        // Au clavier seulement (Entrée, Espace) : la carte va à la meilleure place
        if (card) {
            if (event.detail === 0 && (this.board.targets[card.dataset.card] ?? []).length > 0) {
                this.move(card.dataset.card, this.bestPlace(card.dataset.card));
            }

            return true;
        }

        return false;
    }

    // Un simple clic : sur une carte de la hauteur juste au-dessus, sinon sur une pile vide, sinon sur le Joker
    bestPlace(code) {
        const places = this.board.targets[code];
        const columns = places.filter((place) => place.startsWith('colonne-'));
        const filled = columns.find((place) => this.board.columns[Number(place.slice(8))].length > 0);

        return filled ?? columns[0] ?? places[0];
    }

    move(card, to) {
        this.send('deplacements', { card, to });
    }

    placeUnder(under) {
        const joker = under.find((element) => element.classList.contains('spider__joker'));

        return this.columnUnder(under) ?? (joker ? `joker-${joker.dataset.joker}` : null);
    }
}
