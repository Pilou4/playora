// FreeCell : 8 colonnes de cartes visibles, 4 cellules libres et 4 familles.
// Un clic sur une carte l'envoie à la meilleure place (sa famille, une colonne, sinon une cellule) ; on peut aussi la glisser.
// Le reste de la table (temps, glisser-déposer, échanges avec le serveur) vient de PatienceTable.

import PatienceTable from './PatienceTable.js';

const RANKS = { 1: 'as', 11: 'valet', 12: 'dame', 13: 'roi' };

export default class FreecellTable extends PatienceTable {
    constructor(element) {
        super(element);

        this.cells = [...element.querySelectorAll('.patience__cell')];
        this.foundations = [...element.querySelectorAll('.patience__foundation')];
    }

    // ---------- Affichage ----------

    renderBoard() {
        this.renderColumns(this.board.columns);

        this.cells.forEach((slot, index) => {
            const code = this.board.cells[index];
            slot.replaceChildren(...(code ? [this.cardElement(code, this.isMovable(code))] : []));
        });

        this.foundations.forEach((slot) => {
            const count = this.board.foundations[slot.dataset.suit];
            const mark = slot.querySelector('.solitaire__foundation-mark');

            slot.replaceChildren(mark);

            if (count > 0) {
                slot.append(this.cardElement(`${slot.dataset.suit}-${RANKS[count] ?? count}`, false));
            }
        });
    }

    // ---------- Actions du joueur ----------

    // Comme les vrais jeux : à la souris, on glisse les cartes (un clic ne les déplace pas) ; un double-clic envoie
    // une carte sur sa famille. Au clavier seulement (Entrée, Espace) : la carte va à la meilleure place
    handleClick(event) {
        const card = this.clickedCard(event);

        if (!card) {
            return false;
        }

        if (event.detail === 0) {
            this.move(card.dataset.card, this.bestPlace(this.board.targets[card.dataset.card] ?? []));
        } else if (event.detail === 2 && (this.board.targets[card.dataset.card] ?? []).includes('famille')) {
            this.move(card.dataset.card, 'famille');
        }

        return true;
    }

    // Sa famille d'abord, puis une colonne où poser la carte sur une autre, puis une colonne vide, puis une cellule
    bestPlace(places) {
        const columns = places.filter((place) => place.startsWith('colonne-'));
        const filled = columns.find((place) => this.board.columns[Number(place.slice(8))].length > 0);

        return places.find((place) => place === 'famille') ?? filled ?? columns[0] ?? places[0];
    }

    move(card, to) {
        if (to) {
            this.send('deplacements', { card, to });
        }
    }

    placeUnder(under) {
        const cell = under.find((element) => element.classList.contains('patience__cell'));
        const foundation = under.find((element) => element.classList.contains('patience__foundations'));

        return this.columnUnder(under) ?? (cell ? `cellule-${cell.dataset.cell}` : null) ?? (foundation ? 'famille' : null);
    }
}
