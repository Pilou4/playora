// Éditeur d'avatar « portrait » : catégories, vignettes, couleurs, âge, Retour / Suite,
// Nouveau, Aléatoire et Fou. Le serveur vérifie le code reçu : il n'accepte que des pièces qui existent.
import AvatarRenderer from './AvatarRenderer.js';

const PAGE_SIZE = 15;
// Accessoires : souvent absents dans un tirage « Aléatoire », souvent présents dans un tirage « Fou »
const ACCESSORIES = ['barbe', 'collier', 'lunettes', 'chapeau', 'special'];

export default class AvatarEditor {
    static init(root = document) {
        root.querySelectorAll('.avatar-page').forEach((element) => new AvatarEditor(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.piecesUrl = dataset.piecesUrl;
        this.saveUrl = dataset.saveUrl;
        this.iconsUrl = dataset.iconsUrl;
        this.csrf = dataset.csrf;
        this.initialCode = dataset.code || dataset.preview;

        this.preview = element.querySelector('.avatar-editor__image');
        this.undoButton = element.querySelector('.avatar-editor__undo');
        this.redoButton = element.querySelector('.avatar-editor__redo');
        this.age = element.querySelector('.avatar-age');
        this.categories = element.querySelector('.avatar-editor__categories');
        this.currentImage = element.querySelector('.avatar-editor__current-image');
        this.currentLabel = element.querySelector('.avatar-editor__current-label');
        this.tabs = element.querySelector('.avatar-editor__tabs');
        this.grid = element.querySelector('.avatar-editor__grid');
        this.colorsToggle = element.querySelector('.avatar-editor__colors-toggle');
        this.swatches = element.querySelector('.avatar-editor__swatches');
        this.colors = element.querySelector('.avatar-colors');
        this.saveButton = element.querySelector('.avatar-editor__save');
        this.status = element.querySelector('.avatar-editor__status');

        this.renderer = null;
        this.style = null;
        this.choices = [];
        this.past = [];
        this.future = [];
        this.slot = null;
        this.tab = null;
        this.page = 0;
        this.busy = false;

        this.onClick = this.onClick.bind(this);
        this.onKey = this.onKey.bind(this);
    }

    async start() {
        this.element.addEventListener('click', this.onClick);
        document.addEventListener('keydown', this.onKey);

        try {
            const response = await fetch(this.piecesUrl, { headers: { Accept: 'application/json' } });
            this.renderer = new AvatarRenderer(await response.json());
        } catch {
            this.say('Les pièces n’ont pas pu être chargées : rechargez la page.');

            return;
        }

        const parsed = this.renderer.parse(this.initialCode) ?? this.renderer.parse(this.renderer.catalog.gallery[0]);
        this.style = parsed.style;
        this.choices = parsed.choices;
        this.slot = this.slotIndex('cheveux');
        this.buildCategories();
        this.buildAge();
        this.update();
    }

    get code() {
        return AvatarRenderer.code(this.style, this.choices);
    }

    slotIndex(key) {
        return this.style.slots.findIndex((slot) => slot.key === key);
    }

    // ---------- Construction ----------

    buildCategories() {
        const buttons = this.style.slots.flatMap((slot, index) => {
            if (slot.type !== 'piece') {
                return [];
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'avatar-category';
            button.dataset.slot = String(index);
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-label', slot.label);
            button.title = slot.label;
            button.append(this.image(`${this.iconsUrl}avatar-${slot.key}.svg`, 'avatar-category__icon', 24));

            return [button];
        });

        this.categories.replaceChildren(...buttons);
    }

    buildAge() {
        const index = this.slotIndex('age');

        this.age.replaceChildren(...this.style.slots[index].options.map((option, value) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'avatar-age__button';
            button.dataset.slot = String(index);
            button.dataset.option = String(value);
            button.textContent = option.label;

            return button;
        }));
    }

    // ---------- Affichage ----------

    update() {
        this.preview.src = this.renderer.url(this.style, this.choices);
        this.undoButton.disabled = this.past.length === 0;
        this.redoButton.disabled = this.future.length === 0;

        const age = this.slotIndex('age');
        this.age.querySelectorAll('.avatar-age__button').forEach((button) => {
            const active = Number(button.dataset.option) === this.choices[age];
            button.classList.toggle('avatar-age__button--active', active);
            button.setAttribute('aria-pressed', String(active));
        });

        this.renderHead();
        this.renderTabs();
        this.renderGrid();
        this.renderSwatches();

        if (this.colors.classList.contains('avatar-colors--open')) {
            this.renderColors();
        }
    }

    renderHead() {
        const slot = this.style.slots[this.slot];

        this.currentImage.src = `${this.iconsUrl}avatar-${slot.key}.svg`;
        this.currentLabel.textContent = slot.label;
        this.categories.querySelectorAll('.avatar-category').forEach((button) => {
            const active = Number(button.dataset.slot) === this.slot;
            button.classList.toggle('avatar-category--active', active);
            button.setAttribute('aria-selected', String(active));
        });
    }

    renderTabs() {
        const slot = this.style.slots[this.slot];
        const tabs = [[null, 'Tous'], ...(slot.tabs ?? [])];

        this.tabs.replaceChildren(...tabs.map(([tag, label]) => {
            const button = document.createElement('button');
            const active = tag === this.tab;

            button.type = 'button';
            button.className = `avatar-tab${active ? ' avatar-tab--active' : ''}`;
            button.dataset.tag = tag ?? '';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', String(active));
            button.textContent = label;

            return button;
        }));
    }

    // Les vignettes : l'avatar entier avec cette pièce, cadré sur la pièce (slot.view)
    renderGrid() {
        const slot = this.style.slots[this.slot];
        const entries = slot.options
            .map((option, index) => ({ option, index }))
            .filter(({ option }) => this.tab === null || option.tag === this.tab);
        const pages = Math.max(1, Math.ceil(entries.length / (PAGE_SIZE - 1)));
        const paged = entries.length > PAGE_SIZE;

        this.page %= pages;
        const shown = paged ? entries.slice(this.page * (PAGE_SIZE - 1), (this.page + 1) * (PAGE_SIZE - 1)) : entries;
        const tiles = shown.map(({ option, index }) => this.tile(slot, option, index));

        if (paged) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'avatar-tile avatar-tile--more';
            more.textContent = 'Suite';
            tiles.push(more);
        }

        this.grid.setAttribute('aria-label', slot.label);
        this.grid.replaceChildren(...tiles);
    }

    tile(slot, option, index) {
        const slotIndex = this.style.slots.indexOf(slot);
        const button = document.createElement('button');
        const selected = this.choices[slotIndex] === index;
        const none = slot.none && index === 0;

        button.type = 'button';
        button.className = `avatar-tile${none ? ' avatar-tile--none' : ''}${selected ? ' avatar-tile--selected' : ''}`;
        button.dataset.slot = String(slotIndex);
        button.dataset.option = String(index);
        button.setAttribute('aria-pressed', String(selected));
        button.setAttribute('aria-label', option.label);
        button.title = option.label;

        if (!none) {
            const choices = [...this.choices];
            choices[slotIndex] = index;
            button.append(this.image(this.renderer.url(this.style, choices, slot.view), 'avatar-tile__image', 96));
        }

        return button;
    }

    // Les couleurs actuelles, en pastilles sur le bouton « Régler les couleurs »
    renderSwatches() {
        this.swatches.replaceChildren(...this.colorSlots().map(({ slot, index }) => (
            this.image(AvatarRenderer.swatch(slot.options[this.choices[index]].swatch), 'avatar-editor__swatch', 14)
        )));
    }

    renderColors() {
        this.colors.replaceChildren(...this.colorSlots().map(({ slot, index }) => {
            const group = document.createElement('div');
            const title = document.createElement('p');
            const list = document.createElement('div');

            group.className = 'avatar-colors__group';
            title.className = 'avatar-colors__title';
            title.id = `avatar-couleur-${slot.key}`;
            title.textContent = slot.label;
            list.className = 'avatar-colors__list';
            list.setAttribute('role', 'group');
            list.setAttribute('aria-labelledby', title.id);
            list.append(...slot.options.map((option, value) => {
                const button = document.createElement('button');
                const selected = this.choices[index] === value;

                button.type = 'button';
                button.className = `avatar-colors__choice${selected ? ' avatar-colors__choice--selected' : ''}`;
                button.dataset.slot = String(index);
                button.dataset.option = String(value);
                button.setAttribute('aria-pressed', String(selected));
                button.setAttribute('aria-label', option.label);
                button.title = option.label;
                button.append(this.image(AvatarRenderer.swatch(option.swatch), 'avatar-colors__swatch', 28));

                return button;
            }));
            group.append(title, list);

            return group;
        }));
    }

    colorSlots() {
        return this.style.slots.map((slot, index) => ({ slot, index })).filter(({ slot }) => slot.type === 'color');
    }

    image(src, className, size) {
        const image = document.createElement('img');
        image.className = className;
        image.src = src;
        image.alt = '';
        image.width = size;
        image.height = size;

        return image;
    }

    // ---------- Actions ----------

    onClick(event) {
        const target = event.target.closest('button');

        if (!target || !this.renderer) {
            return;
        }

        const { classList, dataset } = target;

        if (classList.contains('avatar-category')) {
            this.slot = Number(dataset.slot);
            this.tab = null;
            this.page = 0;
            this.update();
        } else if (classList.contains('avatar-tab')) {
            this.tab = dataset.tag || null;
            this.page = 0;
            this.update();
        } else if (classList.contains('avatar-tile--more')) {
            this.page += 1;
            this.renderGrid();
        } else if (dataset.option !== undefined) {
            this.change(Number(dataset.slot), Number(dataset.option));
        } else if (target === this.undoButton) {
            this.travel(this.past, this.future);
        } else if (target === this.redoButton) {
            this.travel(this.future, this.past);
        } else if (classList.contains('avatar-editor__new')) {
            this.apply(this.renderer.parse(this.renderer.catalog.gallery[0]).choices);
        } else if (classList.contains('avatar-editor__random')) {
            this.apply(this.draw(false));
        } else if (classList.contains('avatar-editor__crazy')) {
            this.apply(this.draw(true));
        } else if (target === this.colorsToggle) {
            this.toggleColors(!this.colors.classList.contains('avatar-colors--open'));
        } else if (target === this.saveButton) {
            this.save();
        }
    }

    onKey(event) {
        if (event.key === 'Escape' && this.colors.classList.contains('avatar-colors--open')) {
            this.toggleColors(false);
            this.colorsToggle.focus();
        }
    }

    toggleColors(open) {
        this.colors.classList.toggle('avatar-colors--open', open);
        this.colorsToggle.setAttribute('aria-expanded', String(open));

        if (open) {
            this.renderColors();
        }
    }

    change(slot, option) {
        if (this.choices[slot] === option) {
            return;
        }

        const choices = [...this.choices];
        choices[slot] = option;
        this.apply(choices);
    }

    // Chaque changement se garde dans l'historique (Retour / Suite)
    apply(choices) {
        this.past.push(this.choices);
        this.future = [];
        this.choices = choices;
        this.update();
    }

    travel(from, to) {
        if (from.length > 0) {
            to.push(this.choices);
            this.choices = from.pop();
            this.update();
        }
    }

    // Aléatoire : des avatars crédibles (pas de pièce « rare », peu d'accessoires).
    // Fou : tout est permis, accessoires compris.
    draw(crazy) {
        const pick = (list) => list[Math.floor(Math.random() * list.length)];

        return this.style.slots.map((slot) => {
            const all = slot.options.map((option, index) => ({ option, index }));
            const usual = all.filter(({ option }) => !option.rare);

            if (slot.key === 'age') {
                return crazy ? pick(all).index : 1;
            }

            if (ACCESSORIES.includes(slot.key)) {
                const chance = crazy ? 0.75 : (slot.key === 'special' ? 0 : 0.25);

                return Math.random() < chance ? pick((crazy ? all : usual).slice(1)).index : 0;
            }

            return pick(crazy ? all : usual).index;
        });
    }

    async save() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.saveButton.disabled = true;

        try {
            const response = await fetch(this.saveUrl, {
                method: 'PUT',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify({ code: this.code }),
            });
            const payload = await response.json();

            if (response.ok) {
                this.refreshAccountMenu(payload.face);
            }

            this.say(response.ok ? 'Votre avatar est enregistré.' : payload.detail ?? 'L’enregistrement n’a pas abouti.');
        } catch {
            this.say('Connexion perdue : réessayez dans un instant.');
        } finally {
            this.busy = false;
            this.saveButton.disabled = false;
        }
    }

    // Le menu du compte (en-tête) montre tout de suite le nouvel avatar, sans recharger la page
    refreshAccountMenu(face) {
        const current = document.querySelector('.account-menu__toggle .account-menu__avatar');

        if (!current || !face) {
            return;
        }

        const image = document.createElement('img');
        image.className = 'account-menu__avatar';
        image.src = face;
        image.width = 34;
        image.height = 34;
        image.alt = '';
        current.replaceWith(image);
    }

    say(message) {
        this.status.textContent = message;
    }
}
