// Golf solitaire : 7 colonnes de cartes visibles ; la carte du dessus d'une colonne va sur la pile si elle a une
// hauteur de plus ou de moins. Un clic suffit (ou un glisser jusqu'à la pile) ; le talon donne une nouvelle carte.

import PatienceTable from './PatienceTable.js';

export default class GolfSolitaireTable extends PatienceTable {
    constructor(element) {
        super(element);

        this.stock = element.querySelector('.patience__stock');
        this.pile = element.querySelector('.patience__pile');
        this.passes = element.querySelector('.patience__passes');
    }

    renderBoard() {
        const { columns, pile, stock, canDraw } = this.board;

        this.renderColumns(columns);
        this.pile.replaceChildren(this.cardElement(pile, false));
        this.stock.replaceChildren(...(stock > 0 ? [this.cardElement(null, false)] : []));
        this.stock.disabled = !this.playing || !canDraw;
        this.stock.classList.toggle('solitaire__stock--empty', stock === 0);
        this.stock.setAttribute('aria-label', stock > 0 ? `Talon : ${stock} carte${stock > 1 ? 's' : ''}, retourner une carte sur la pile` : 'Talon vide');
        this.passes.textContent = `${stock} carte${stock > 1 ? 's' : ''} au talon`;
    }

    handleClick(event) {
        if (event.target.closest('.patience__stock') && this.playing) {
            this.send('talon', {});

            return true;
        }

        const card = this.clickedCard(event);

        if (card) {
            this.move(card.dataset.card, 'pile');

            return true;
        }

        return false;
    }

    move(card, to) {
        this.send('deplacements', { card, to });
    }

    placeUnder(under) {
        return under.some((element) => element.classList.contains('patience__pile')) ? 'pile' : null;
    }
}
