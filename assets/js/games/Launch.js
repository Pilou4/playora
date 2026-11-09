// Écran de lancement : compte à rebours de 3 secondes, puis la table.
const STEP = 1000;

export default class Launch {
    static init(root = document) {
        root.querySelectorAll('.launch').forEach((element) => new Launch(element).start());
    }

    constructor(element) {
        this.element = element;
        this.count = element.querySelector('.launch__count');
        this.remaining = Number.parseInt(this.count.textContent, 10);
        this.tick = this.tick.bind(this);
    }

    start() {
        this.element.classList.add('launch--animated');
        this.timer = window.setInterval(this.tick, STEP);
    }

    tick() {
        this.remaining -= 1;

        if (this.remaining > 0) {
            this.count.textContent = String(this.remaining);

            return;
        }

        window.clearInterval(this.timer);
        this.element.classList.add('launch--started');
    }
}
