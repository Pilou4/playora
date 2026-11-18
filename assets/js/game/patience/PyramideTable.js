// Pyramide : 28 cartes en 7 rangées ; on enlève deux cartes libres qui font 13 (le Roi part seul).
// Un clic choisit une carte (elle se soulève), un clic sur une carte qui fait 13 avec elle enlève la paire ;
// on peut aussi glisser l'une sur l'autre. La carte du dessus de la défausse se joue comme les autres.

import PatienceTable from './PatienceTable.js';

export default class PyramideTable extends PatienceTable {
    constructor(element) {
        super(element);

        this.slots = [...element.querySelectorAll('.patience__slot')];
        this.stock = element.querySelector('.patience__stock');
        this.waste = element.querySelector('.patience__waste');
        this.passes = element.querySelector('.patience__passes');
        this.selected = null;

        // Place de chaque carte dans la pyramide (rangée, rang dans la rangée), pour le CSS
        this.slots.forEach((slot) => {
            const index = Number(slot.dataset.index);
            const row = Number(slot.dataset.row);
            slot.style.setProperty('--row', String(row));
            slot.style.setProperty('--col', String(index - (row * (row + 1)) / 2));
        });
    }

    renderBoard() {
        const { pyramid, free, waste, stock, canDraw, passesLeft } = this.board;

        if (this.selected && !Object.hasOwn(this.board.targets, this.selected)) {
            this.selected = null;
        }

        this.slots.forEach((slot, index) => {
            const code = pyramid[index];
            const card = code ? this.cardElement(code, this.isMovable(code)) : null;

            card?.classList.toggle('patience__card--covered', !free.includes(index));
            slot.replaceChildren(...(card ? [this.mark(card)] : []));
        });

        this.waste.replaceChildren(...(waste ? [this.mark(this.cardElement(waste, this.isMovable(waste)))] : []));
        this.stock.replaceChildren(...(stock > 0 ? [this.cardElement(null, false)] : []));
        this.stock.disabled = !this.playing || !canDraw;
        this.stock.classList.toggle('solitaire__stock--empty', stock === 0);

        if (stock === 0 && canDraw) {
            const recycle = document.createElement('span');
            recycle.className = 'solitaire__recycle';
            recycle.textContent = '↻';
            this.stock.append(recycle);
        }

        this.stock.setAttribute('aria-label', stock > 0 ? `Talon : ${stock} carte${stock > 1 ? 's' : ''}, retourner une carte` : 'Remettre la défausse dans le talon');
        this.passes.textContent = passesLeft > 0 ? `Talon : encore ${passesLeft} passage${passesLeft > 1 ? 's' : ''}` : 'Dernier passage du talon';
    }

    // La carte choisie se soulève
    mark(card) {
        card.classList.toggle('patience__card--selected', card.dataset.card === this.selected);

        return card;
    }

    handleClick(event) {
        if (event.target.closest('.patience__stock') && this.playing) {
            this.selected = null;
            this.send('talon', {});

            return true;
        }

        const card = this.clickedCard(event);

        if (!card) {
            return false;
        }

        const code = card.dataset.card;
        const places = this.board.targets[code] ?? [];

        if (places.includes('seul')) {
            this.selected = null;
            this.move(code, 'seul');
        } else if (this.selected && this.selected !== code && (this.board.targets[this.selected] ?? []).includes(code)) {
            const first = this.selected;
            this.selected = null;
            this.move(first, code);
        } else {
            this.selected = this.selected === code ? null : code;
            this.renderBoard();
        }

        return true;
    }

    move(card, to) {
        this.send('deplacements', { card, to });
    }

    // Lâchée sur une autre carte (pas une copie du glisser) : la paire
    placeUnder(under) {
        const target = under.find((element) => element.dataset?.card
            && !this.dragLayer.contains(element)
            && !element.classList.contains('solitaire__card--lifted'));

        return target?.dataset.card ?? null;
    }
}
