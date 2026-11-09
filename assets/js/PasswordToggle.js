export default class PasswordToggle {
    static init(root = document) {
        root.querySelectorAll('.field__toggle').forEach((button) => new PasswordToggle(button));
    }

    constructor(button) {
        this.button = button;
        this.input = document.getElementById(button.getAttribute('aria-controls'));
        this.label = button.querySelector('.field__toggle-text');

        if (!this.input || !this.label) {
            return;
        }

        this.toggle = this.toggle.bind(this);
        this.button.addEventListener('click', this.toggle);
        this.button.hidden = false;
    }

    toggle() {
        const visible = this.input.type === 'password';

        this.input.type = visible ? 'text' : 'password';
        this.label.textContent = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
        this.button.setAttribute('aria-pressed', String(visible));
        this.button.classList.toggle('field__toggle--visible', visible);
    }
}
