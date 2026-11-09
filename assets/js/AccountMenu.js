// Menu du compte (avatar + pseudo) : s'ouvre au clic, se ferme au clic dehors ou avec Échap.
export default class AccountMenu {
    static init(root = document) {
        root.querySelectorAll('.account-menu').forEach((element) => new AccountMenu(element).start());
    }

    constructor(element) {
        this.element = element;
        this.toggle = element.querySelector('.account-menu__toggle');
        this.firstItem = element.querySelector('.account-menu__item');

        this.onToggle = this.onToggle.bind(this);
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onFocusOut = this.onFocusOut.bind(this);
    }

    start() {
        this.toggle.addEventListener('click', this.onToggle);
        document.addEventListener('click', this.onDocumentClick);
        this.element.addEventListener('keydown', this.onKeydown);
        this.element.addEventListener('focusout', this.onFocusOut);
    }

    get isOpen() {
        return this.element.classList.contains('account-menu--open');
    }

    onToggle() {
        this.setOpen(!this.isOpen);

        if (this.isOpen) {
            this.firstItem?.focus();
        }
    }

    onDocumentClick(event) {
        if (this.isOpen && !this.element.contains(event.target)) {
            this.setOpen(false);
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape' && this.isOpen) {
            this.setOpen(false);
            this.toggle.focus();
        }
    }

    // Au clavier, quitter le menu avec Tab le referme
    onFocusOut(event) {
        if (this.isOpen && event.relatedTarget && !this.element.contains(event.relatedTarget)) {
            this.setOpen(false);
        }
    }

    setOpen(open) {
        this.element.classList.toggle('account-menu--open', open);
        this.toggle.setAttribute('aria-expanded', String(open));
    }
}
