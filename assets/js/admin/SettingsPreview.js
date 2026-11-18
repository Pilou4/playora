// Réglages d'un jeu : l'aperçu de la table suit le tapis, les faces et le dos des cartes choisis, sans recharger la page
export default class SettingsPreview {
    static init(root = document) {
        root.querySelectorAll('.admin-settings').forEach((element) => new SettingsPreview(element).start());
    }

    constructor(element) {
        this.element = element;
        this.preview = element.querySelector('.admin-preview--main');
        this.carpets = [...element.querySelectorAll('.admin-choice__input--carpet')].map((input) => input.value);
        this.onChange = this.onChange.bind(this);
    }

    start() {
        this.element.addEventListener('change', this.onChange);
    }

    onChange(event) {
        const input = event.target;

        if (input.classList.contains('admin-choice__input--carpet')) {
            this.showCarpet(input.value);
        } else if (input.classList.contains('admin-choice__input--cards')) {
            this.showCards(input.dataset.path, input.dataset.ext ?? 'svg');
        } else if (input.classList.contains('admin-choice__input--back')) {
            this.showBack(input.dataset.url);
        }
    }

    showCarpet(carpet) {
        this.carpets.forEach((name) => this.preview.classList.toggle(`admin-preview--${name}`, name === carpet));
    }

    // Toutes les cartes des aperçus (grande table et petits tapis) prennent le jeu choisi
    showCards(path, ext) {
        this.element.querySelectorAll('.admin-preview__card:not(.admin-preview__card--back)').forEach((card) => {
            card.src = `${path}${card.dataset.card}.${ext}`;
        });
    }

    showBack(url) {
        this.preview.querySelectorAll('.admin-preview__card--back').forEach((card) => {
            card.src = url;
        });
    }
}
