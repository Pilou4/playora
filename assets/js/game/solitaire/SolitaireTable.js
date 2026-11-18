// Solitaire (Klondike) : talon, cartes retournées, quatre familles et sept piles de tri.
// Le reste de la table (temps, joueurs, glisser-déposer, échanges avec le serveur) vient de PatienceTable.
//
// Comme le solitaire des ordinateurs : on attrape n'importe quelle carte visible d'une pile (elle part avec celles
// posées dessus) et on la lâche où elle va ; un double-clic sur une carte du dessus l'envoie sur sa famille.
// Au clavier, Entrée sur une carte la joue à la meilleure place (la famille d'abord).

import PatienceTable, { formatScore } from '../patience/PatienceTable.js';
import Sound from '../Sound.js';

// Pioche : chaque carte glisse du talon vers la droite en se retournant, avec un léger décalage entre les cartes
const DRAW_DURATION = 320;
const DRAW_STAGGER = 70;
// Cartes montées seules sur leur famille, comme le solitaire des ordinateurs : chacune part de sa place
// (cartes retournées, bas d'une pile), file vers sa famille, puis la suivante (l'As, puis le 2 par-dessus…)
const AUTO_DURATION = 300;
const AUTO_GAP = 230;
const AUTO_FIRST = 90;
// Fin de partie qui se joue toute seule (beaucoup de cartes) : plus vite, comme sur les ordinateurs
const RUSH_DURATION = 240;
const RUSH_GAP = 85;
// À l'arrivée sur la table : la donne se voit un instant avant que ses As (et 2) montent
const DEAL_PAUSE = 450;
// Jeu résolu : les familles sautillent avant le résultat
const CELEBRATION = 1100;
const RANKS = ['as', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'valet', 'dame', 'roi'];
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

export default class SolitaireTable extends PatienceTable {
    static init(root = document) {
        root.querySelectorAll('.solitaire').forEach((element) => new SolitaireTable(element).start());
    }

    constructor(element) {
        super(element);

        this.stock = element.querySelector('.solitaire__stock');
        this.waste = element.querySelector('.solitaire__waste');
        this.foundations = [...element.querySelectorAll('.solitaire__foundation')];
        // Après un clic sur le talon : combien de cartes il a retournées (seules celles-là glissent du talon)
        this.drawn = 0;
        // Cartes à faire monter sur leur famille après l'affichage, et leurs minuteries
        this.pending = [];
        this.autoTimers = [];
        // Le résultat final attend la fin des cartes qui montent, quand la partie se finit à ce coup
        this.overlayTimer = null;
        this.finishing = false;
        // Premier affichage : les cartes de la donne montent quand la table apparaît (revealed)
        this.arriving = true;

        this.onDoubleClick = this.onDoubleClick.bind(this);
    }

    start() {
        this.element.addEventListener('dblclick', this.onDoubleClick);
        super.start();
    }

    // ---------- Affichage ----------

    render(view, animate = true) {
        const auto = view.board.auto ?? [];
        const fromStock = auto.filter(({ from }) => from === 'talon').length;

        this.autoTimers.forEach((timer) => window.clearTimeout(timer));
        this.autoTimers = [];
        // Les cartes montées seules à ce coup sont d'abord dessinées à leur place d'avant, puis elles montent une à une.
        // À l'arrivée sur la table, celles de la donne aussi (les As et les 2 visibles), une fois la table apparue
        const arriving = this.arriving && (view.phase === 'jeu' || view.phase === 'intro');
        this.pending = (animate || arriving) && !REDUCED.matches ? auto : [];
        // Cartes retournées par ce clic sur le talon, y compris celles qui montent ensuite seules
        this.drawn = this.action === 'talon' ? Math.max(0, view.board.wasteCount - this.board.wasteCount) + fromStock : 0;
        this.finishing = animate && this.view.phase === 'jeu';
        super.render(view, animate);
        this.drawn = 0;
        this.finishing = false;
    }

    renderBoard() {
        this.renderStock();
        this.renderWaste();
        this.renderFoundations();
        // Une carte qui monte seule depuis une pile y est encore, en bas, avant de partir
        const tableau = this.board.tableau.map((column) => [...column]);

        [...this.pending].reverse().forEach(({ card, from }) => {
            if (from.startsWith('colonne-')) {
                tableau[Number(from.replace('colonne-', ''))]?.push(card);
            }
        });
        this.renderColumns(tableau);
    }

    renderStock() {
        const { stock, wasteCount, recycleCost, lastCards } = this.board;

        this.stock.replaceChildren();
        this.stock.disabled = !this.playing || (stock === 0 && wasteCount === 0);
        this.stock.classList.toggle('solitaire__stock--empty', stock === 0);
        this.stock.classList.toggle('solitaire__stock--done', Boolean(lastCards));
        if (lastCards) {
            this.stock.setAttribute('aria-disabled', 'true');
        } else {
            this.stock.removeAttribute('aria-disabled');
        }

        if (lastCards) {
            // Les dernières cartes du talon sont toutes visibles : plus rien à retourner
            this.stock.setAttribute('aria-label', 'Talon terminé : les dernières cartes sont visibles');
        } else if (stock > 0) {
            this.stock.append(this.cardElement(null, false));
            this.stock.setAttribute('aria-label', `Talon : ${stock} carte${stock > 1 ? 's' : ''}, retourner une carte`);
        } else {
            const recycle = document.createElement('span');
            recycle.className = 'solitaire__recycle';
            recycle.textContent = recycleCost > 0 ? `↻ −${formatScore(recycleCost)}` : '↻';
            this.stock.append(recycle);
            this.stock.setAttribute('aria-label', recycleCost > 0 ? `Remettre le talon en place (−${recycleCost} points)` : 'Remettre le talon en place');
        }
    }

    // L'éventail des cartes retournées, posé sur la carte d'en dessous (visible dès qu'on prend celle du dessus)
    renderWaste() {
        // Les cartes retournées qui montent seules sont encore sur l'éventail, au-dessus (la dernière montée était dessous)
        const cards = [...this.board.waste, ...this.pending.filter(({ from }) => from === 'talon').map(({ card }) => card).reverse()];
        const under = this.board.under ? [this.cardElement(this.board.under, false)] : [];

        under.forEach((card) => card.style.setProperty('--i', '0'));
        this.waste.replaceChildren(...under, ...cards.map((code, index) => {
            const card = this.cardElement(code, index === this.board.waste.length - 1 && this.isMovable(code));
            card.style.setProperty('--i', String(index));

            return card;
        }));
    }

    // Cartes piochées : elles partent du talon et glissent sur le côté en se retournant, l'une après l'autre.
    // Seulement celles que le clic vient de retourner : les cartes d'avant qui complètent l'éventail étaient déjà là, dessous.
    enter(card) {
        if (card.parentElement !== this.waste) {
            super.enter(card);

            return;
        }

        const cards = [...this.waste.children];
        const first = cards.length - this.drawn;
        const index = cards.indexOf(card);

        if (this.drawn === 0 || index < first) {
            return;
        }

        const from = this.stock.getBoundingClientRect();
        const to = card.getBoundingClientRect();
        const dx = from.left - to.left;
        const dy = from.top - to.top;
        const delay = (index - first) * DRAW_STAGGER;

        Sound.card(delay);

        card.classList.add('solitaire__card--drawn', 'solitaire__card--moving');
        card.animate(
            [
                { transform: `translate(${dx}px, ${dy}px) scaleX(0.05)`, offset: 0 },
                { transform: `translate(${dx * 0.55}px, ${dy * 0.55}px) scaleX(1)`, offset: 0.45 },
                { transform: 'none' },
            ],
            { duration: DRAW_DURATION, delay, easing: 'cubic-bezier(.25, .8, .25, 1)', fill: 'backwards' },
        ).finished.then(() => card.classList.remove('solitaire__card--drawn', 'solitaire__card--moving'), () => {});
    }

    // Chaque famille montre sa carte du dessus ; pendant que des cartes y montent seules, celle d'avant elles
    renderFoundations() {
        this.foundations.forEach((slot) => {
            const suit = slot.dataset.suit;
            const rising = this.pending.filter(({ card }) => card.startsWith(`${suit}-`)).map(({ card }) => RANKS.indexOf(card.split('-')[1]));
            const code = rising.length > 0
                ? (Math.min(...rising) > 0 ? `${suit}-${RANKS[Math.min(...rising) - 1]}` : null)
                : this.board.foundations[suit];
            const mark = slot.querySelector('.solitaire__foundation-mark');

            // La carte d'en dessous est déjà là : quand on reprend celle du dessus, elle apparaît aussitôt
            const rank = code ? RANKS.indexOf(code.split('-')[1]) : -1;
            const below = rank > 0 ? [this.cardElement(`${suit}-${RANKS[rank - 1]}`, false)] : [];

            slot.replaceChildren(mark, ...below);

            if (code) {
                slot.append(this.cardElement(code, rising.length === 0 && this.isMovable(code)));
            }
        });
    }

    slide(before) {
        super.slide(before);
        this.riseAuto();
    }

    // La table est apparue : les cartes montées seules (à la donne, ou au dernier coup avant un rechargement) partent
    // de leur pile vers leur famille
    revealed() {
        this.arriving = false;
        // Un court instant pour voir la donne, puis elles montent
        this.autoTimers.push(window.setTimeout(() => this.riseAuto(), DEAL_PAUSE));
    }

    // Les cartes montent une à une, après la pioche (si elle a eu lieu) : chacune quitte sa place et file vers sa famille
    riseAuto() {
        const auto = this.pending;
        const { start, gap, duration } = this.autoTiming(auto);

        this.pending = [];
        auto.forEach(({ card }, index) => {
            this.autoTimers.push(window.setTimeout(() => this.rise(card, duration), start + index * gap));
        });
    }

    // Quand les cartes commencent à monter, l'écart entre deux cartes, la durée d'un vol ; et la fin de la dernière
    autoTiming(auto) {
        const rush = auto.length > 4;
        const start = this.drawn > 0 ? (this.drawn - 1) * DRAW_STAGGER + DRAW_DURATION : AUTO_FIRST;
        const gap = rush ? RUSH_GAP : AUTO_GAP;
        const duration = rush ? RUSH_DURATION : AUTO_DURATION;

        return { start, gap, duration, end: auto.length === 0 ? 0 : start + (auto.length - 1) * gap + duration };
    }

    // Partie finie à ce coup : le résultat attend que les cartes soient montées (et, jeu résolu, une petite fête)
    renderOverlay() {
        window.clearTimeout(this.overlayTimer);
        const over = this.view.phase === 'attente' || this.view.phase === 'terminee';
        const { end } = this.autoTiming(this.pending);

        if (!over || !this.finishing || (end === 0 && !this.board.complete)) {
            super.renderOverlay();

            return;
        }

        this.overlay.classList.remove('solitaire__overlay--visible', 'solitaire__overlay--result');
        this.overlayTimer = window.setTimeout(() => {
            if (this.board.complete) {
                this.celebrate();
            }

            this.overlayTimer = window.setTimeout(() => super.renderOverlay(), this.board.complete ? CELEBRATION : 0);
        }, end);
    }

    // Jeu résolu : les quatre Rois sautillent l'un après l'autre, et un petit mot
    celebrate() {
        this.foundations.forEach((slot, index) => slot.style.setProperty('--n', String(index)));
        this.felt.classList.add('solitaire__felt--won');
        this.showToast('Bravo, jeu résolu !', CELEBRATION);
    }

    rise(code, duration = AUTO_DURATION) {
        const card = [...this.felt.querySelectorAll('.solitaire__card[data-card]')]
            .find((element) => element.dataset.card === code && !element.parentElement.classList.contains('solitaire__foundation') && !this.dragLayer.contains(element));
        const slot = this.foundations.find((element) => element.dataset.suit === code.split('-')[0]);

        if (!card || !slot) {
            return;
        }

        const from = card.getBoundingClientRect();

        card.getAnimations().forEach((animation) => animation.cancel());
        card.classList.remove('solitaire__card--movable');
        card.style.removeProperty('--i');
        card.style.removeProperty('--y');
        slot.append(card);
        this.layoutColumns();

        const to = card.getBoundingClientRect();

        card.classList.add('solitaire__card--moving');
        card.animate(
            [{ transform: `translate(${from.left - to.left}px, ${from.top - to.top}px)` }, { transform: 'translate(0, 0)' }],
            { duration, easing: 'cubic-bezier(.3, .7, .25, 1)' },
        ).finished.then(() => card.classList.remove('solitaire__card--moving'), () => {});
    }

    // ---------- Actions du joueur ----------

    handleClick(event) {
        const card = this.clickedCard(event);

        if (event.target.closest('.solitaire__stock') && this.playing) {
            if (this.board.lastCards) {
                this.showToast('Plus rien à piocher : les dernières cartes du talon sont toutes visibles.');

                return true;
            }

            this.send('talon', { auto: true });

            return true;
        }

        if (card) {
            const places = this.board.targets[card.dataset.card] ?? [];

            // Au clavier seulement (Entrée, Espace) : la famille d'abord, sinon la première pile qui accepte la carte.
            // À la souris, un simple clic ne fait rien : on glisse la carte, ou on double-clique.
            if (event.detail === 0 && places.length > 0) {
                this.move(card.dataset.card, places.includes('famille') ? 'famille' : places[0]);
            }

            return true;
        }

        return false;
    }

    // Double-clic sur une carte du dessus (d'une pile ou des cartes retournées) : elle monte sur sa famille si elle y va
    onDoubleClick(event) {
        const card = this.clickedCard(event);

        if (!card || !this.isOnTop(card)) {
            return;
        }

        if ((this.board.targets[card.dataset.card] ?? []).includes('famille')) {
            this.move(card.dataset.card, 'famille');
        }
    }

    // Carte du dessus : la dernière d'une pile de tri, ou celle qu'on peut jouer des cartes retournées
    isOnTop(card) {
        const parent = card.parentElement;

        return (parent.classList.contains('solitaire__column') || parent.classList.contains('solitaire__waste')) && parent.lastElementChild === card;
    }

    // Toute carte visible se prend (avec celles posées dessus) ; elle ne se lâche que là où elle va
    isMovable(code) {
        return this.playing && code !== null;
    }

    move(card, to) {
        this.place(card, to);
        this.send('deplacements', { card, to, auto: true });
    }

    // Familles : la place de la couleur de la carte
    placeContainer(code, place) {
        return place === 'famille' ? this.foundations.find((slot) => slot.dataset.suit === code.split('-')[0]) ?? null : super.placeContainer(code, place);
    }

    placeUnder(under) {
        const foundation = under.find((element) => element.classList.contains('solitaire__foundations'));

        return this.columnUnder(under) ?? (foundation ? 'famille' : null);
    }
}
