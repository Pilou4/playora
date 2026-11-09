// Assemble un avatar à partir des pièces (assets/avatars/pieces.json).
// Même règle que src/Avatar/AvatarRenderer.php : le serveur et le navigateur dessinent pareil.
const CODE = /^[a-z]+(?:\.\d{1,2}){1,24}$/;

export default class AvatarRenderer {
    constructor(catalog) {
        this.catalog = catalog;
        this.styles = new Map(catalog.styles.map((style) => [style.key, style]));
    }

    parse(code) {
        if (!CODE.test(code ?? '')) {
            return null;
        }

        const [key, ...rest] = code.split('.');
        const style = this.styles.get(key);
        const choices = rest.map(Number);

        if (!style || choices.length !== style.slots.length || choices.some((choice, index) => !style.slots[index].options[choice])) {
            return null;
        }

        return { style, choices };
    }

    static code(style, choices) {
        return [style.key, ...choices].join('.');
    }

    // viewBox : tout le portrait (par défaut), le visage (style.face) ou un cadrage de vignette
    render(style, choices, viewBox = style.viewBox) {
        const options = Object.fromEntries(style.slots.map((slot, index) => [slot.key, slot.options[choices[index]]]));
        const tokens = Object.assign({}, ...Object.values(options).map((option) => option.tokens ?? {}));
        const body = style.layers.map((layer) => layer.draw ?? options[layer.slot].parts?.[layer.part ?? 'main'] ?? '').join('');
        let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}"><defs>${style.defs}</defs>${body}</svg>`;

        // Deux passes : une couleur peut renvoyer à une autre
        for (let pass = 0; pass < 2; pass += 1) {
            svg = svg.replace(/\{(\w+)\}/g, (match, name) => tokens[name] ?? '');
        }

        return svg;
    }

    // Image autonome (data:) : aucun SVG n'est inséré dans la page
    url(style, choices, viewBox) {
        return `data:image/svg+xml,${encodeURIComponent(this.render(style, choices, viewBox))}`;
    }

    static swatch(color) {
        const fill = /^#[0-9a-f]{6}$/i.test(color) ? color : '#ffffff';
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="10" fill="${fill}"/></svg>`;

        return `data:image/svg+xml,${encodeURIComponent(svg)}`;
    }
}
