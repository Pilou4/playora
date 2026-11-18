// Les sons des tables de jeu (Web Audio). Une carte distribuée : de vrais enregistrements de cartes qui glissent
// (public/sons/cartes/carte-1.ogg à carte-8.ogg, pris au hasard et légèrement variés) ; tant qu'ils ne sont pas là,
// un froissement fabriqué dans le navigateur. D'autres sons viendront.
// Le son se coupe et se remet avec le bouton des tables (SoundButton) ; le choix est gardé dans ce navigateur.
const KEY = 'playora-son';
const CARD_LENGTH = 0.07;
const CARD_FILES = Array.from({ length: 8 }, (_, index) => `/sons/cartes/carte-${index + 1}.ogg`);
const CARD_VOLUME = 0.8;

export default class Sound {
    static context = null;
    static noise = null;
    // Les enregistrements des cartes, une fois chargés
    static cards = [];
    static listeners = new Set();
    // Choix gardé pour la page quand le stockage du navigateur est indisponible
    static mutedHere = null;

    static isMuted() {
        try {
            return window.localStorage.getItem(KEY) === 'coupe';
        } catch {
            return false;
        }
    }

    static setMuted(muted) {
        try {
            if (muted) {
                window.localStorage.setItem(KEY, 'coupe');
            } else {
                window.localStorage.removeItem(KEY);
            }
        } catch {
            // Stockage indisponible : le choix vaut pour cette page
            Sound.mutedHere = muted;
        }

        Sound.listeners.forEach((listener) => listener(muted));
    }

    static muted() {
        return Sound.mutedHere ?? Sound.isMuted();
    }

    static onChange(listener) {
        Sound.listeners.add(listener);
    }

    // Les navigateurs ne jouent un son qu'après un geste du joueur : le contexte se réveille au premier clic
    static audio() {
        if (Sound.context === null) {
            const Context = window.AudioContext ?? window.webkitAudioContext;

            if (!Context) {
                return null;
            }

            Sound.context = new Context();
            Sound.loadCards(Sound.context);
            const wake = () => Sound.context.resume().catch(() => {});
            window.addEventListener('pointerdown', wake, { once: true });
            window.addEventListener('keydown', wake, { once: true });
        }

        return Sound.context;
    }

    // Les enregistrements se chargent en arrière-plan ; ceux qui manquent sont simplement ignorés
    static loadCards(context) {
        CARD_FILES.forEach((url) => {
            fetch(url)
                .then((response) => (response.ok ? response.arrayBuffer() : Promise.reject(new Error(url))))
                .then((data) => context.decodeAudioData(data))
                .then((buffer) => Sound.cards.push(buffer))
                .catch(() => {});
        });
    }

    // Un bruit blanc court, gardé une fois fabriqué
    static noiseBuffer(context) {
        if (Sound.noise === null) {
            const length = Math.ceil(context.sampleRate * CARD_LENGTH);
            Sound.noise = context.createBuffer(1, length, context.sampleRate);
            const data = Sound.noise.getChannelData(0);

            for (let i = 0; i < length; i += 1) {
                data[i] = Math.random() * 2 - 1;
            }
        }

        return Sound.noise;
    }

    // Une carte distribuée (delay : dans combien de millisecondes)
    static card(delay = 0) {
        const context = Sound.muted() ? null : Sound.audio();

        if (!context || context.state !== 'running') {
            return;
        }

        const start = context.currentTime + delay / 1000;

        if (Sound.cards.length > 0) {
            Sound.recorded(context, start);

            return;
        }

        const source = context.createBufferSource();
        const filter = context.createBiquadFilter();
        const gain = context.createGain();

        source.buffer = Sound.noiseBuffer(context);
        // Un peu différent à chaque carte, comme un vrai jeu
        filter.type = 'bandpass';
        filter.frequency.value = 2600 + Math.random() * 1400;
        filter.Q.value = 0.9;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.35, start + 0.004);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + CARD_LENGTH);
        source.connect(filter).connect(gain).connect(context.destination);
        source.start(start);
        source.stop(start + CARD_LENGTH);
    }

    // Une donne : une carte après l'autre (au plus 24 bruits, le temps d'une distribution)
    static deal(count, stagger = 55) {
        for (let index = 0; index < Math.min(count, 24); index += 1) {
            Sound.card(index * stagger);
        }
    }

    // Une carte au hasard, jouée un peu plus vite ou plus lentement : jamais deux fois le même bruit
    static recorded(context, start) {
        const source = context.createBufferSource();
        const gain = context.createGain();

        source.buffer = Sound.cards[Math.floor(Math.random() * Sound.cards.length)];
        source.playbackRate.value = 0.94 + Math.random() * 0.14;
        gain.gain.value = CARD_VOLUME * (0.85 + Math.random() * 0.15);
        source.connect(gain).connect(context.destination);
        source.start(start);
    }
}

// Le bouton des tables : un clic coupe le son, un autre le remet
export class SoundButton {
    static start(root) {
        root.querySelectorAll('.belote__tool--sound').forEach((button) => new SoundButton(button).start());
    }

    constructor(button) {
        this.button = button;
        this.icon = button.querySelector('img');
        this.label = button.querySelector('.visually-hidden');
        this.onClick = this.onClick.bind(this);
        this.show = this.show.bind(this);
    }

    start() {
        this.button.addEventListener('click', this.onClick);
        Sound.onChange(this.show);
        Sound.audio();
        this.show(Sound.muted());
    }

    onClick() {
        Sound.setMuted(!Sound.muted());
    }

    show(muted) {
        const text = muted ? 'Remettre le son' : 'Couper le son';

        this.icon.src = muted ? this.button.dataset.off : this.button.dataset.on;
        this.button.title = text;
        this.button.setAttribute('aria-pressed', String(muted));
        this.label.textContent = text;
    }
}
