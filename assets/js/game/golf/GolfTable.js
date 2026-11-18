// Table du Golf (disposition A). Le serveur décide de tout : la page envoie « je retourne cette carte »,
// « je pioche », « je prends la défausse pour cette place », « j'échange », « je jette et je retourne »,
// puis affiche la vue renvoyée par le serveur, qui fait foi. Les cartes glissent de leur ancienne place à la nouvelle.
//
// À son tour : un clic sur la pioche pioche ; un clic sur la défausse la choisit, puis un clic sur une de ses cartes
// fait l'échange. Avec une carte piochée : un clic sur une de ses cartes l'échange ; « Jeter la carte » (ou un clic
// sur la défausse), puis un clic sur une carte cachée, la jette en retournant cette carte.

import { BackGuard, LeaveDialog, Toast, toggleFullscreen } from '../TableControls.js';
import Sound, { SoundButton } from '../Sound.js';

const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const RANK_LABELS = { as: 'As', valet: 'Valet', dame: 'Dame', roi: 'Roi' };
// Places des adversaires autour de la table, selon leur nombre (du joueur suivant, à gauche, jusqu'au précédent, à droite)
const SEATS = {
    1: ['top'],
    2: ['top-left', 'top-right'],
    3: ['left', 'top', 'right'],
    4: ['left', 'top-left', 'top-right', 'right'],
    5: ['left', 'top-left', 'top', 'top-right', 'right'],
};
const START_FLIPS = 2;
const COLUMNS = 3;

// Les places des cartes qui font une paire dans leur colonne (même hauteur, toutes deux visibles) : elles comptent 0
const pairs = (grid) => {
    const places = new Set();

    for (let column = 0; column < COLUMNS; column += 1) {
        const [top, bottom] = [grid[column], grid[column + COLUMNS]];

        if (top && bottom && top.split('-')[1] === bottom.split('-')[1]) {
            places.add(column).add(column + COLUMNS);
        }
    }

    return places;
};
const MOVE_DURATION = 260;
// Fin d'un trou : toutes les cartes retournées quelques secondes, puis les points (le serveur fait attendre le trou suivant)
const REVEAL_DURATION = 3500;
const RESULTS_DURATION = 4500;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

export default class GolfTable {
    static init(root = document) {
        root.querySelectorAll('.golf').forEach((element) => new GolfTable(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.cardsUrl = dataset.cardsUrl;
        this.backUrl = dataset.backUrl;
        this.view = JSON.parse(dataset.view);

        this.felt = element.querySelector('.rami__felt');
        this.opponents = element.querySelector('.rami__opponents');
        this.stock = element.querySelector('.golf__stock');
        this.discard = element.querySelector('.golf__discard');
        this.drawn = element.querySelector('.golf__drawn');
        this.grid = element.querySelector('.golf__grid--me');
        this.tag = element.querySelector('.golf__tag--me');
        this.hint = element.querySelector('.rami__hint');
        this.throwButton = element.querySelector('.golf__throw');
        this.clockFill = element.querySelector('.rami__clock-fill');
        this.scoreValue = element.querySelector('.rami__score-value');
        this.holeValue = element.querySelector('.rami__round');
        this.overlay = element.querySelector('.rami__overlay');
        this.toast = new Toast(element.querySelector('.belote__toast'));
        this.leaveDialog = new LeaveDialog({ dialog: element.querySelector('.rami__leave-dialog'), leaveUrl: dataset.leaveUrl, lobbyUrl: dataset.lobbyUrl, csrf: this.csrf });

        // Choix en cours : null, « defausse » (la carte de la défausse est choisie) ou « jeter » (la carte piochée sera jetée)
        this.mode = null;
        this.busy = false;
        // Dernière version annoncée par Mercure, et une mise à jour en cours : aucune annonce n'est perdue
        this.latestVersion = 0;
        this.fetching = false;
        this.deadline = 0;
        this.timeoutSent = false;
        this.clockTimer = null;
        this.resultsTimer = null;
        this.shownHole = this.view.lastHole?.number ?? 0;
        // Le trou qui vient de finir, montré cartes retournées (null le reste du temps)
        this.revealed = null;
        this.revealTimer = null;

        this.onClick = this.onClick.bind(this);
        this.onResize = this.onResize.bind(this);
        this.onUpdate = this.onUpdate.bind(this);
        this.tick = this.tick.bind(this);
        this.hideResults = this.hideResults.bind(this);
        this.endReveal = this.endReveal.bind(this);
    }

    start() {
        // « Précédent » du navigateur : la partie est quittée (un robot prend la place), sans retour possible
        new BackGuard({ leaveUrl: this.element.dataset.leaveUrl, exitUrl: this.element.dataset.lobbyUrl, csrf: this.csrf, isOver: () => this.view.phase === 'terminee' }).start(this.element);
        // Bouton du son : coupé ou remis, gardé dans ce navigateur
        SoundButton.start(this.element);
        this.element.addEventListener('click', this.onClick);
        window.addEventListener('resize', this.onResize);
        this.onResize();
        this.render(this.view, false);

        if (this.hubUrl && 'EventSource' in window) {
            new EventSource(this.hubUrl).addEventListener('message', this.onUpdate);
        }
    }

    // La table occupe tout l'écran sous l'en-tête du site
    onResize() {
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        this.element.style.setProperty('--offset', `${Math.round(top)}px`);
    }

    // ---------- État ----------

    get myTurn() {
        return this.view.phase === 'jeu' && this.view.turn === this.view.me;
    }

    get myGrid() {
        return this.player(this.view.me)?.grid ?? [];
    }

    // Au début du trou : il me reste des cartes à retourner
    get mustReveal() {
        return this.view.phase === 'debut' && this.myGrid.filter((code) => code !== null).length < START_FLIPS;
    }

    player(position) {
        return this.view.players.find((player) => player.position === position);
    }

    // ---------- Affichage ----------

    // Affiche la vue ; une annonce arrivée entre-temps (pendant un coup du joueur) est rattrapée aussitôt
    render(view, animate = true) {
        this.paint(view, animate);
        this.catchUp();
    }

    paint(view, animate = true) {
        const before = animate ? this.positions() : new Map();

        // Nouvelle vue du serveur : le temps du tour repart d'elle
        if (view !== this.view || this.deadline === 0) {
            this.deadline = performance.now() + view.secondsLeft * 1000;
            this.timeoutSent = false;
        }

        // Nouvelle donne (nouvelle manche) : le bruit des cartes distribuées
        if (animate && this.view && view.hole !== this.view.hole) {
            Sound.deal(6 * view.players.length);
        }

        this.view = view;

        if (!this.myTurn || (this.mode === 'defausse' && view.drawn) || (this.mode === 'jeter' && !view.drawn)) {
            this.mode = null;
        }

        this.holeValue.textContent = String(view.hole);
        this.scoreValue.textContent = String(this.player(view.me)?.score ?? 0);
        this.checkHoleEnd();
        this.renderOpponents();
        this.renderCenter();
        this.renderGrid();
        this.renderActions();
        this.tick();

        if (animate) {
            this.slide(before);
        }
    }

    positions() {
        const positions = new Map();

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => positions.set(card.dataset.card, card.getBoundingClientRect()));

        return positions;
    }

    // Chaque carte glisse de son ancienne place à la nouvelle ; une carte nouvelle apparaît en douceur
    slide(before) {
        if (REDUCED.matches) {
            return;
        }

        this.felt.querySelectorAll('.rami__card[data-card]').forEach((card) => {
            const from = before.get(card.dataset.card);

            if (!from) {
                card.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });

                return;
            }

            const to = card.getBoundingClientRect();
            const dx = from.left - to.left;
            const dy = from.top - to.top;

            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                card.animate(
                    [{ transform: `translate(${dx}px, ${dy}px) scale(${from.width / to.width})`, transformOrigin: 'top left' }, { transform: 'none', transformOrigin: 'top left' }],
                    { duration: MOVE_DURATION, easing: 'cubic-bezier(.2, .8, .2, 1)' },
                );
            }
        });
    }

    // Une carte (null : un dos)
    cardElement(code) {
        const card = document.createElement('span');
        const image = document.createElement('img');

        card.className = 'card rami__card';
        image.className = 'card__face';
        image.draggable = false;

        if (code === null) {
            card.classList.add('card--back');
            image.src = this.backUrl;
            image.alt = '';
        } else {
            const [suit, rank] = code.split('-');
            card.dataset.card = code;
            image.src = `${this.cardsUrl}${suit}/${rank}.svg`;
            image.alt = this.label(code);
        }

        card.append(image);

        return card;
    }

    label(code) {
        const [suit, rank] = code.split('-');

        return `${RANK_LABELS[rank] ?? rank} de ${SUIT_LABELS[suit]}`;
    }

    // Une grille de 6 cartes (2 lignes de 3), pour un adversaire
    gridElement(cards) {
        const grid = document.createElement('ol');
        const paired = pairs(cards);

        grid.className = 'golf__grid';
        grid.append(...cards.map((code, index) => {
            const item = document.createElement('li');
            item.className = `golf__cell${paired.has(index) ? ' golf__cell--pair' : ''}`;
            item.append(this.cardElement(code));

            return item;
        }));

        return grid;
    }

    // Les autres joueurs autour de la table : leurs 6 cartes, leur nom et leurs points
    renderOpponents() {
        const { players, me, turn, phase, closer } = this.view;
        const count = players.length;
        const others = [];

        for (let step = 1; step < count; step += 1) {
            others.push(players.find((player) => player.position === (me + step) % count));
        }

        this.opponents.replaceChildren(...others.map((player, index) => {
            const element = document.createElement('div');
            const tag = document.createElement('div');
            const avatar = document.createElement('span');
            const name = document.createElement('p');
            const info = document.createElement('p');

            element.className = `golf__opponent golf__opponent--${SEATS[others.length][index]}${player.position === turn && phase === 'jeu' ? ' golf__opponent--turn' : ''}`;
            tag.className = 'rami__opponent';
            avatar.className = 'rami__avatar';

            if (player.avatar) {
                const image = document.createElement('img');
                image.src = player.avatar;
                image.alt = '';
                image.width = 40;
                image.height = 40;
                avatar.append(image);
            } else {
                avatar.textContent = player.pseudo.charAt(0).toUpperCase();
            }

            name.className = 'rami__opponent-name';
            name.textContent = player.pseudo;
            info.className = 'rami__opponent-info';
            info.textContent = this.revealed
                ? `${this.signed(this.revealed.points[player.position])} sur ce trou`
                : `${player.score} pts${player.position === closer ? ' · a tout retourné' : ''}`;
            tag.append(avatar, name, info);
            element.append(this.gridElement(this.revealed ? this.revealed.grids[player.position] : player.grid), tag);

            return element;
        }));
    }

    // La pioche, la carte du dessus de la défausse et la carte piochée
    renderCenter() {
        const { stock, discard, drawn } = this.view;
        const free = this.myTurn && !this.busy;
        const canDraw = free && !drawn && stock > 0;
        const canTakeDiscard = free && discard !== null;

        this.stock.replaceChildren(...(stock > 0 ? [this.cardElement(null)] : []), this.pileLabel(`Pioche · ${stock}`));
        this.stock.disabled = !canDraw;
        this.stock.classList.toggle('golf__pile--active', canDraw);
        this.stock.setAttribute('aria-label', `Piocher (${stock} cartes)`);

        this.discard.replaceChildren(...(discard ? [this.cardElement(discard)] : []), this.pileLabel('Défausse'));
        this.discard.disabled = !canTakeDiscard;
        this.discard.classList.toggle('golf__pile--active', canTakeDiscard);
        this.discard.classList.toggle('golf__pile--selected', this.mode === 'defausse' || this.mode === 'jeter');
        this.discard.setAttribute('aria-label', drawn ? 'Jeter la carte piochée' : `Prendre la carte de la défausse${discard ? ` (${this.label(discard)})` : ''}`);
        this.discard.setAttribute('aria-pressed', String(this.mode !== null));

        if (drawn) {
            const card = this.cardElement(drawn);
            card.classList.add('golf__drawn-card');
            this.drawn.replaceChildren(card, this.pileLabel('Carte piochée'));
        } else {
            this.drawn.replaceChildren();
        }
    }

    pileLabel(text) {
        const label = document.createElement('span');
        label.className = 'rami__pile-label';
        label.textContent = text;

        return label;
    }

    // Mes 6 cartes : chaque carte est un bouton, actif quand on peut la choisir
    renderGrid() {
        const me = this.player(this.view.me);
        const grid = this.revealed ? this.revealed.grids[this.view.me] : this.myGrid;
        const paired = pairs(grid);

        this.grid.replaceChildren(...grid.map((code, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            const target = !this.revealed && this.isTarget(index);

            item.className = `golf__cell${paired.has(index) ? ' golf__cell--pair' : ''}`;
            button.type = 'button';
            button.className = 'golf__slot';
            button.dataset.index = String(index);
            button.classList.toggle('golf__slot--target', target);
            button.disabled = !target;
            button.setAttribute('aria-label', `${index < COLUMNS ? 'En haut' : 'En bas'}, carte ${(index % COLUMNS) + 1} : ${code === null ? 'cachée' : this.label(code)}${paired.has(index) ? ' (paire : 0 point)' : ''}`);
            button.append(this.cardElement(code));
            item.append(button);

            return item;
        }));

        this.tag.textContent = this.revealed
            ? `Vous · ${this.signed(this.revealed.points[this.view.me])} sur ce trou`
            : `Vous · ${me?.score ?? 0} pts${this.view.closer === this.view.me ? ' · tout est retourné' : ''}`;
        this.tag.classList.toggle('golf__tag--turn', this.myTurn);
    }

    // Peut-on choisir cette carte maintenant ?
    isTarget(index) {
        if (this.busy) {
            return false;
        }

        const hidden = this.myGrid[index] === null;

        if (this.view.phase === 'debut') {
            return this.mustReveal && hidden;
        }

        if (!this.myTurn) {
            return false;
        }

        if (this.mode === 'jeter') {
            return hidden;
        }

        return this.mode === 'defausse' || this.view.drawn !== null;
    }

    renderActions() {
        const { phase, drawn, closer } = this.view;
        const last = closer !== null && phase === 'jeu' ? ' Dernier tour !' : '';

        this.throwButton.hidden = !this.myTurn || !drawn;
        this.throwButton.disabled = this.busy;
        this.throwButton.classList.toggle('rami__button--brand', this.mode === 'jeter');
        this.throwButton.setAttribute('aria-pressed', String(this.mode === 'jeter'));

        if (this.revealed) {
            this.hint.textContent = `Fin du trou ${this.revealed.number} : toutes les cartes sont retournées.`;
        } else if (phase === 'debut') {
            this.hint.textContent = this.mustReveal ? 'Retournez 2 cartes de votre choix.' : 'Les autres joueurs retournent leurs cartes…';
        } else if (phase !== 'jeu') {
            this.hint.textContent = '';
        } else if (!this.myTurn) {
            this.hint.textContent = `À ${this.player(this.view.turn)?.pseudo ?? 'un autre joueur'} de jouer.${last}`;
        } else if (this.mode === 'jeter') {
            this.hint.textContent = `Choisissez la carte cachée à retourner.${last}`;
        } else if (drawn) {
            this.hint.textContent = `Échangez la carte piochée avec une de vos cartes, ou jetez-la.${last}`;
        } else if (this.mode === 'defausse') {
            this.hint.textContent = `Choisissez la carte à remplacer par celle de la défausse.${last}`;
        } else {
            this.hint.textContent = `À vous : piochez, ou prenez la carte de la défausse.${last}`;
        }
    }

    // Un trou vient de finir : ses cartes, toutes retournées, restent à l'écran quelques secondes, puis les points.
    // Fin de partie : le classement tout de suite (les cartes du dernier trou sont déjà retournées).
    checkHoleEnd() {
        const { lastHole, phase } = this.view;

        if (phase === 'terminee') {
            this.revealed = null;
            this.showResults(true);

            return;
        }

        if (!lastHole || lastHole.number <= this.shownHole) {
            return;
        }

        this.shownHole = lastHole.number;

        if (!lastHole.grids?.length) {
            this.showResults(false);

            return;
        }

        this.revealed = lastHole;
        window.clearTimeout(this.revealTimer);
        this.revealTimer = window.setTimeout(this.endReveal, REVEAL_DURATION);
    }

    endReveal() {
        const hole = this.revealed;

        this.revealed = null;
        this.render(this.view);

        if (hole && this.view.phase !== 'terminee') {
            this.showResults(false, hole);
        }
    }

    signed(points) {
        return `${points > 0 ? '+' : ''}${points ?? 0} pt${Math.abs(points ?? 0) > 1 ? 's' : ''}`;
    }

    // Le panneau des points : fin d'un trou (quelques secondes), ou fin de la partie (jusqu'au retour aux salons)
    showResults(finished, hole = this.view.lastHole) {
        const title = this.overlay.querySelector('.rami__panel-title');
        const list = this.overlay.querySelector('.rami__results');
        const link = this.overlay.querySelector('.rami__panel-link');

        link.classList.toggle('rami__panel-link--visible', finished);

        if (finished) {
            const winner = this.player(this.view.winner);
            title.textContent = this.view.draw ? 'Égalité !' : (this.view.winner === this.view.me ? 'Vous avez gagné !' : `${winner?.pseudo ?? 'Un autre joueur'} gagne la partie`);
        } else {
            title.textContent = `Fin du trou ${hole.number}`;
        }

        list.replaceChildren(...[...this.view.players].sort((a, b) => a.score - b.score).map((player) => {
            const row = document.createElement('li');
            const points = hole?.points[player.position];

            row.className = 'rami__result';
            row.textContent = `${player.position === this.view.me ? 'Vous' : player.pseudo} : ${player.score} pts${!finished && points !== undefined ? ` (${points >= 0 ? '+' : ''}${points})` : ''}`;

            return row;
        }));

        this.overlay.classList.add('rami__overlay--visible');
        window.clearTimeout(this.resultsTimer);

        if (!finished) {
            this.resultsTimer = window.setTimeout(this.hideResults, RESULTS_DURATION);
        }
    }

    hideResults() {
        this.overlay.classList.remove('rami__overlay--visible');
    }

    // ---------- Temps ----------

    // La barre se vide pendant le tour ; à zéro, n'importe quel joueur demande au serveur de jouer pour le retardataire
    tick() {
        window.clearTimeout(this.clockTimer);

        if (this.view.phase !== 'jeu' && this.view.phase !== 'debut') {
            this.clockFill.style.setProperty('--left', '0');

            return;
        }

        const left = Math.max(0, (this.deadline - performance.now()) / 1000);
        this.clockFill.style.setProperty('--left', Math.min(1, left / this.view.seconds).toFixed(4));
        this.clockFill.classList.toggle('rami__clock-fill--late', left <= 5);

        if (left <= 0) {
            this.clockTimer = window.setTimeout(() => this.sendTimeout(), this.myTurn || this.mustReveal ? 1200 : 2500);

            return;
        }

        this.clockTimer = window.setTimeout(this.tick, 250);
    }

    sendTimeout() {
        if (!this.timeoutSent) {
            this.timeoutSent = true;
            this.send('temps-ecoule', {}, true);
        }
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const target = event.target;
        const slot = target.closest('.golf__slot');

        if (slot && !slot.disabled) {
            this.choose(Number(slot.dataset.index));
        } else if (target.closest('.golf__stock')) {
            this.send('pioche', {});
        } else if (target.closest('.golf__discard') || target.closest('.golf__throw')) {
            this.toggleMode();
        } else if (target.closest('.belote__tool--rules')) {
            this.element.querySelector('.belote__rules').showModal();
        } else if (target.closest('.belote__rules-close')) {
            this.element.querySelector('.belote__rules').close();
        } else if (target.closest('.belote__quit')) {
            this.leaveDialog.open(this.view.phase === 'terminee');
        } else if (target.closest('.rami__leave-cancel')) {
            this.leaveDialog.close();
        } else if (target.closest('.rami__leave-confirm')) {
            this.leaveDialog.confirm(target.closest('.rami__leave-confirm'));
        } else if (target.closest('.belote__tool--fullscreen')) {
            toggleFullscreen(this.element.querySelector('.belote__frame'));
        } else if (target.closest('.rami__overlay') && this.view.phase !== 'terminee') {
            this.hideResults();
        }
    }

    // La défausse : la choisir (sans carte piochée) ou y jeter la carte piochée ; un second clic annule
    toggleMode() {
        if (!this.myTurn || this.busy) {
            return;
        }

        const mode = this.view.drawn ? 'jeter' : 'defausse';
        this.mode = this.mode === mode ? null : mode;
        this.renderCenter();
        this.renderGrid();
        this.renderActions();
    }

    choose(index) {
        if (this.view.phase === 'debut') {
            this.send('retourner', { index });
        } else if (this.mode === 'jeter') {
            this.send('jeter', { index });
        } else if (this.view.drawn) {
            this.send('echanger', { index });
        } else if (this.mode === 'defausse') {
            this.send('defausse/prendre', { index });
        }
    }

    // ---------- Échanges avec le serveur ----------

    async send(action, body, quiet = false) {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.mode = null;
        this.renderGrid();

        try {
            const response = await fetch(`${this.apiUrl}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({}));

            this.busy = false;

            if (response.ok) {
                this.render(payload.view);

                return;
            }

            // Coup refusé par les règles (409, 422) : le message du serveur, en français, est pour le joueur.
            // Toute autre erreur reste technique : jamais affichée telle quelle, la table se remet à jour.
            const refused = response.status === 409 || response.status === 422;

            if (!quiet) {
                this.toast.show(refused ? payload.detail ?? 'Coup refusé.' : 'Un souci est survenu : la partie reprend.', 2600);
            }

            if (payload.view) {
                this.render(payload.view);
            } else {
                await this.refresh();
            }
        } catch {
            this.busy = false;
            this.toast.show('Connexion perdue : la partie reprend.', 2600);
            await this.refresh();
        }
    }

    async refresh() {
        this.fetching = true;

        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                const { view } = await response.json();
                this.fetching = false;

                if (!this.busy && view.version >= this.view.version) {
                    this.render(view);
                }
            }
        } catch {
            // Réseau coupé : le prochain message relancera la mise à jour
        } finally {
            this.fetching = false;
        }
    }

    // Un autre joueur a joué (Mercure). Pendant un coup du joueur, l'annonce n'est plus ignorée :
    // elle est gardée et la table se met à jour juste après (sinon cet écran prenait du retard sur les autres)
    onUpdate(message) {
        const { version } = JSON.parse(message.data);

        this.latestVersion = Math.max(this.latestVersion, version);
        this.catchUp();
    }

    catchUp() {
        if (!this.busy && !this.fetching && this.latestVersion > this.view.version) {
            this.refresh();
        }
    }
}
