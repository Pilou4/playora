// Messages en haut de l'écran (enregistré, erreur) : ils glissent, restent quelques secondes puis disparaissent
const DURATION = 4000;

export default class AdminFlash {
    static init() {
        const element = document.querySelector('.admin-flash');

        if (element) {
            AdminFlash.instance = new AdminFlash(element);
            element.querySelectorAll('.admin-flash__message').forEach((message) => AdminFlash.instance.schedule(message));
        }
    }

    // Utilisable par les autres scripts de l'admin : AdminFlash.show('Texte', 'success' | 'error')
    static show(text, type = 'success') {
        AdminFlash.instance?.show(text, type);
    }

    constructor(element) {
        this.element = element;
        this.onClick = this.onClick.bind(this);
        element.addEventListener('click', this.onClick);
    }

    show(text, type) {
        this.element.replaceChildren();
        const message = document.createElement('p');
        message.className = `admin-flash__message admin-flash__message--${type}`;
        message.textContent = text;
        this.element.append(message);
        this.schedule(message);
    }

    schedule(message) {
        window.setTimeout(() => this.hide(message), DURATION);
    }

    hide(message) {
        message.classList.add('admin-flash__message--leaving');
        message.addEventListener('animationend', () => message.remove(), { once: true });
    }

    // Un clic sur le message le ferme tout de suite
    onClick(event) {
        const message = event.target.closest('.admin-flash__message');

        if (message) {
            this.hide(message);
        }
    }
}
