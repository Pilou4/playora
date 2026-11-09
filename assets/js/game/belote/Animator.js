// Animations des cartes (Web Animations API).
// Une carte « vole » d'un point à un autre dans un calque posé sur la table : elle garde les
// tailles de la table (variables CSS), change de taille en vol et peut se retourner à mi-chemin.
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');
const EASE = 'cubic-bezier(.25,.8,.25,1)';

export const wait = (ms) => new Promise((resolve) => {
    window.setTimeout(resolve, REDUCED.matches ? 0 : ms);
});

export default class Animator {
    constructor(layer) {
        this.layer = layer;
    }

    // Centre et largeur d'un élément, dans le repère du calque
    box(element, width = null) {
        const rect = element.getBoundingClientRect();
        const origin = this.layer.getBoundingClientRect();

        return { x: rect.left - origin.left + rect.width / 2, y: rect.top - origin.top + rect.height / 2, width: width ?? rect.width };
    }

    // Largeur réelle d'une carte d'une catégorie donnée (main, pli, dos)
    measure(variable) {
        const probe = document.createElement('span');
        probe.className = 'card flying flying--probe';
        probe.style.setProperty('--w', `var(${variable})`);
        this.layer.append(probe);
        const { width } = probe.getBoundingClientRect();
        probe.remove();

        return width;
    }

    // card : élément .card affiché au départ ; reveal : élément affiché après le retournement ;
    // keep : la carte reste posée à l'arrivée (pour repartir ensuite), sinon elle disparaît
    async fly(card, from, to, { duration = 420, delay = 0, rotate = [0, 0], reveal = null, lift = 1.06, fade = false, keep = false } = {}) {
        card.getAnimations().forEach((animation) => animation.cancel());
        card.classList.add('flying', 'card--shadow');
        card.style.width = `${to.width}px`;
        this.layer.append(card);

        const height = to.width * 1.4;
        const start = from.width / to.width;
        const middle = { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 };
        const middleScale = ((start + 1) / 2) * lift;
        const middleAngle = (rotate[0] + rotate[1]) / 2;
        const at = (point, angle, scale, flip = 1) => `translate(${point.x - to.width / 2}px, ${point.y - height / 2}px) rotate(${angle}deg) scale(${scale * flip}, ${scale})`;

        card.style.transform = at(from, rotate[0], start);

        if (REDUCED.matches) {
            card.remove();

            return null;
        }

        const timing = { easing: EASE, fill: 'forwards' };

        if (reveal) {
            // Première moitié : la carte s'aplatit (tranche) ; seconde moitié : elle s'ouvre sur l'autre face
            await card.animate([
                { transform: at(from, rotate[0], start) },
                { transform: at(middle, middleAngle, middleScale, 0.02) },
            ], { ...timing, duration: duration / 2, delay, easing: 'cubic-bezier(.4,0,1,1)' }).finished;
            card.replaceChildren(...reveal.childNodes);
            card.className = `${reveal.className} flying card--shadow`;
            await card.animate([
                { transform: at(middle, middleAngle, middleScale, 0.02), opacity: 1 },
                { transform: at(to, rotate[1], 1), opacity: fade ? 0 : 1 },
            ], { ...timing, duration: duration / 2, easing: 'cubic-bezier(0,0,.2,1)' }).finished;
        } else {
            await card.animate([
                { transform: at(from, rotate[0], start), opacity: 1 },
                { transform: at(middle, middleAngle, middleScale), opacity: 1, offset: 0.5 },
                { transform: at(to, rotate[1], 1), opacity: fade ? 0 : 1 },
            ], { ...timing, duration, delay }).finished;
        }

        if (keep) {
            card.style.transform = at(to, rotate[1], 1);

            return card;
        }

        card.remove();

        return null;
    }

    // Petite arrivée en douceur (carte qui apparaît à sa place)
    static settle(element) {
        if (!REDUCED.matches) {
            element.animate([{ transform: 'scale(.92)', opacity: 0.4 }, { transform: 'scale(1)', opacity: 1 }], { duration: 180, easing: EASE });
        }
    }
}
