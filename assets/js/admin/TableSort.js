// Tri des tableaux de l'admin dans la page (détail d'une partie) : un clic sur un titre trie, un 2e clic inverse
const COLLATOR = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' });

export default class TableSort {
    static init(root = document) {
        root.querySelectorAll('.admin-sortable').forEach((table) => new TableSort(table).start());
    }

    constructor(table) {
        this.table = table;
        this.body = table.tBodies[0];
        this.onClick = this.onClick.bind(this);
    }

    start() {
        this.table.tHead.addEventListener('click', this.onClick);
    }

    onClick(event) {
        const button = event.target.closest('.admin-sort');

        if (!button) {
            return;
        }

        const header = button.closest('th');
        const index = [...header.parentElement.children].indexOf(header);
        const direction = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';

        this.table.tHead.querySelectorAll('th').forEach((cell) => {
            cell.removeAttribute('aria-sort');
            cell.querySelector('.admin-sort')?.classList.remove('admin-sort--asc', 'admin-sort--desc');
        });
        header.setAttribute('aria-sort', direction);
        button.classList.add(direction === 'ascending' ? 'admin-sort--asc' : 'admin-sort--desc');

        const sign = direction === 'ascending' ? 1 : -1;
        const rows = [...this.body.rows].sort((a, b) => sign * TableSort.compare(TableSort.value(a, index), TableSort.value(b, index)));
        this.body.append(...rows);
    }

    static value(row, index) {
        const cell = row.cells[index];

        return (cell?.dataset.sort ?? cell?.textContent ?? '').trim();
    }

    // Nombres comparés comme des nombres, le reste par ordre alphabétique ; les cases vides (–) à la fin
    static compare(a, b) {
        const empty = (value) => value === '' || value === '–';

        if (empty(a) || empty(b)) {
            return Number(empty(a)) - Number(empty(b));
        }

        const numberA = Number(a.replace(/\s/g, ''));
        const numberB = Number(b.replace(/\s/g, ''));

        if (!Number.isNaN(numberA) && !Number.isNaN(numberB)) {
            return numberA - numberB;
        }

        return COLLATOR.compare(a, b);
    }
}
