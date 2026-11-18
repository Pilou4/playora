// Table de tarot. Même table que la belote (places, pli, minuteur, animations), avec ce qui est propre
// au tarot : enchères (petite, garde, garde sans, garde contre), chien et écart, poignée, chelem,
// scores de chacun. Le serveur décide de tout ; la page rejoue les événements reçus puis prend sa vue.
import Table, { BID_PAUSE } from '../belote/Table.js';
import { wait } from '../belote/Animator.js';

const COLOURS = ['pique', 'coeur', 'trefle', 'carreau'];
const RANKS = { valet: 11, cavalier: 12, dame: 13, roi: 14 };
const SUIT_LABELS = { pique: 'pique', coeur: 'cœur', carreau: 'carreau', trefle: 'trèfle' };
const SUIT_SIGNS = { pique: '♠', coeur: '♥', carreau: '♦', trefle: '♣' };
const FACE_LETTERS = { valet: 'V', cavalier: 'C', dame: 'D', roi: 'R' };
const FACE_NAMES = { valet: 'Valet', cavalier: 'Cavalier', dame: 'Dame', roi: 'Roi' };
const CONTRACTS = { petite: 'Petite', garde: 'Garde', 'garde-sans': 'Garde sans', 'garde-contre': 'Garde contre' };
const CONTRACT_SHORT = { petite: 'P', garde: 'G', 'garde-sans': 'GS', 'garde-contre': 'GC' };
const POIGNEES = { simple: 'Poignée simple', double: 'Poignée double', triple: 'Poignée triple' };
// Places autour de la table, en partant de moi, dans le sens du jeu
const SIDES = {
    3: ['bottom', 'left', 'right'],
    4: ['bottom', 'left', 'top', 'right'],
    5: ['bottom', 'left', 'top-left', 'top-right', 'right'],
};
const TILTS = { bottom: -3, left: -11, top: 4, 'top-left': 6, 'top-right': -5, right: 10 };

export default class TarotTable extends Table {
    static init(root = document) {
        root.querySelectorAll('.tarot').forEach((element) => new TarotTable(element).start());
    }

    constructor(element) {
        super(element);
        this.actions = element.querySelector('.tarot__actions');
        this.selected = new Set();
        this.element.classList.add(`tarot--${this.count}`);
    }

    get count() {
        return this.view.players.length;
    }

    side(position) {
        return SIDES[this.count][(position - this.me + this.count) % this.count];
    }

    trickTilt(position) {
        return TILTS[this.side(position)] ?? 0;
    }

    get dogSize() {
        return this.live.dogSize ?? this.view.dogSize ?? 6;
    }

    // ---------- Cartes ----------

    // Image de la carte, avec un petit indice dans le coin (R ♥, 21…) pour la lire dans l'éventail
    cardElement(code) {
        const card = document.createElement('span');
        const face = document.createElement('img');

        card.className = code ? 'card card--tarot' : 'card card--tarot card--back';
        face.className = 'card__face';
        face.src = code ? `${this.cardsUrl}${code}.webp` : this.backUrl;
        face.alt = code ? this.cardName(code) : '';
        face.draggable = false;
        card.append(face);

        if (code) {
            const index = document.createElement('span');
            const [suit, rank] = code.split('-');
            index.className = `card__index card__index--${code === 'excuse' ? 'excuse' : suit}`;
            index.setAttribute('aria-hidden', 'true');
            index.textContent = code === 'excuse' ? '★' : (suit === 'atout' ? rank : `${FACE_LETTERS[rank] ?? rank}${SUIT_SIGNS[suit]}`);
            card.append(index);
        }

        return card;
    }

    cardName(code) {
        if (code === 'excuse') {
            return 'L’Excuse';
        }

        const [suit, rank] = code.split('-');

        return suit === 'atout' ? `Atout ${rank}` : `${FACE_NAMES[rank] ?? rank} de ${SUIT_LABELS[suit]}`;
    }

    // L'Excuse et les atouts à gauche (du 1 au 21), puis les couleurs en alternant rouge et noir, du 1 au Roi
    sortHand(codes) {
        const key = (code) => {
            if (code === 'excuse') {
                return [0, 0];
            }

            const [suit, rank] = code.split('-');

            return suit === 'atout' ? [1, Number(rank)] : [2 + COLOURS.indexOf(suit), RANKS[rank] ?? Number(rank)];
        };

        return [...codes].sort((a, b) => {
            const [groupA, rankA] = key(a);
            const [groupB, rankB] = key(b);

            return groupA - groupB || rankA - rankB;
        });
    }

    // ---------- Affichage ----------

    render() {
        this.renderPlayers();
        this.renderTrick();
        this.renderHand();
        this.renderBidding();
        this.renderScore();
        this.renderTimer();
        this.renderDeclare();
    }

    renderPlayers() {
        const { counts, turn, taker, phase, contract } = this.live;
        const first = this.firstPlayer();

        this.view.players.forEach((player) => {
            const seat = this.seat(player.position);
            const chip = seat.querySelector('.player__contract');
            const cards = Array.from({ length: counts[player.position] }, (_, index) => {
                const card = this.cardElement(null);
                card.style.setProperty('--i', String(index));
                card.style.setProperty('--n', String(counts[player.position]));

                return card;
            });

            seat.querySelector('.player__fan').replaceChildren(...cards);
            seat.classList.toggle('player--turn', phase !== 'terminee' && turn === player.position);
            seat.classList.toggle('player--first', first === player.position);
            seat.querySelector('.player__pile').classList.add('player__pile--empty');

            // Le contrat sur le preneur : P, G, GS, GC ; à 5 joueurs, une étoile sur son partenaire une fois connu
            const taking = taker === player.position && Boolean(contract);
            const partner = this.live.partner === player.position && this.live.partner !== taker;
            chip.classList.toggle('player__contract--visible', taking || partner);
            chip.classList.toggle('player__contract--value', taking || partner);
            chip.replaceChildren();

            if (taking || partner) {
                const value = document.createElement('span');
                value.className = 'player__contract-value';
                value.textContent = taking ? CONTRACT_SHORT[contract] : '★';
                value.title = taking ? CONTRACTS[contract] : `Partenaire de ${this.player(taker).pseudo}`;
                chip.append(value);
            }
        });
    }

    // Pendant l'écart, le preneur choisit 6 cartes ; pendant le jeu, les cartes permises sont levées
    renderHand() {
        if (!this.isDiscarding()) {
            super.renderHand();
            this.hand.classList.remove('hand--discard');

            return;
        }

        const codes = this.sortHand(this.live.hand);
        const allowed = new Set(this.live.discardable ?? []);
        const dog = new Set(this.live.dog ?? []);

        this.hand.classList.add('hand--active', 'hand--discard');
        this.hand.replaceChildren(...codes.map((code, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'hand__card';
            button.dataset.card = code;
            button.classList.toggle('hand__card--playable', allowed.has(code));
            button.classList.toggle('hand__card--selected', this.selected.has(code));
            button.classList.toggle('hand__card--dog', dog.has(code));
            button.disabled = !allowed.has(code);
            button.setAttribute('aria-pressed', String(this.selected.has(code)));
            button.style.setProperty('--i', String(index));
            button.style.setProperty('--n', String(codes.length));
            button.append(this.cardElement(code));
            item.append(button);

            return item;
        }));
    }

    isDiscarding() {
        return !this.busy && this.live.phase === 'chien' && this.live.taker === this.me && this.live.turn === this.me;
    }

    // Le panneau du centre : enchères, puis le chien (montré à tous) pendant l'écart
    renderBidding() {
        const { phase, turn, taker } = this.live;
        const visible = ['encheres', 'appel', 'chien'].includes(phase) && !this.dealing;
        const mine = visible && turn === this.me && !this.busy;

        this.bidding.classList.toggle('bidding--visible', visible);
        this.bidding.classList.toggle('bidding--mine', mine);
        this.bidding.classList.toggle('bidding--auction', false);
        this.bidding.classList.toggle('bidding--second', false);
        this.bidding.classList.toggle('bidding--dog', visible && phase === 'chien');
        this.biddingCard.replaceChildren();
        this.biddingActions.replaceChildren();
        this.biddingTitle.textContent = '';
        this.biddingText.textContent = '';

        if (!visible) {
            return;
        }

        // À 5 joueurs : le preneur appelle un Roi (les cartes possibles, à cliquer)
        if (phase === 'appel') {
            this.biddingTitle.textContent = taker === this.me ? 'Appelez un Roi' : `${this.player(taker).pseudo} appelle un Roi…`;
            this.biddingText.textContent = taker === this.me ? 'Celui qui l’a jouera avec vous, sans le dire' : `${CONTRACTS[this.live.contract]} de ${this.player(taker).pseudo}`;

            if (mine) {
                (this.live.callable ?? []).forEach((code) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'tarot__call';
                    button.dataset.card = code;
                    button.setAttribute('aria-label', `Appeler : ${this.cardName(code)}`);
                    button.append(this.cardElement(code));
                    this.biddingActions.append(button);
                });
            }

            return;
        }

        if (phase === 'chien') {
            this.biddingCard.replaceChildren(...(this.live.dog ?? []).map((code) => this.cardElement(code)));
            this.biddingTitle.textContent = taker === this.me ? 'Faites votre écart' : `${this.player(taker).pseudo} fait son écart`;

            if (mine && taker === this.me) {
                this.biddingText.textContent = `Choisissez ${this.dogSize} cartes à écarter (${this.selected.size}/${this.dogSize})`;
                const discard = this.button('Écarter', 'tarot__discard bidding__button--action');
                discard.disabled = this.selected.size !== this.dogSize;
                this.biddingActions.append(discard);
            } else {
                this.biddingText.textContent = `${CONTRACTS[this.live.contract]} · le chien`;
            }

            return;
        }

        this.biddingText.textContent = this.bidSummary();

        if (!mine) {
            this.biddingTitle.textContent = turn === this.me ? '' : `${this.player(turn).pseudo} réfléchit…`;

            return;
        }

        this.biddingTitle.textContent = 'À vous de parler';
        this.biddingActions.append(this.button('Passer', 'tarot__bid bidding__button--pass'));
        (this.live.contracts ?? []).forEach((contract) => {
            const button = this.button(CONTRACTS[contract], 'tarot__bid bidding__button--action');
            button.dataset.contract = contract;
            this.biddingActions.append(button);
        });
    }

    bidSummary() {
        const { contract, taker } = this.live;

        return contract ? `Enchère : ${CONTRACTS[contract]} de ${this.player(taker).pseudo}` : 'Aucune enchère pour l’instant';
    }

    button(text, classes) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `bidding__button ${classes}`;
        button.textContent = text;

        return button;
    }

    // Avant ma première carte : annoncer une poignée ou le chelem (seulement ce qui est possible)
    renderDeclare() {
        const { phase, turn } = this.live;
        const buttons = [];

        if (!this.busy && phase === 'jeu' && turn === this.me) {
            (this.live.poigneesPossible ?? []).forEach((poignee) => {
                const button = this.button(POIGNEES[poignee], 'tarot__poignee tarot__action');
                button.dataset.poignee = poignee;
                buttons.push(button);
            });
        }

        if (!this.busy && this.live.chelemPossible && phase !== 'encheres') {
            buttons.push(this.button('Annoncer le chelem', 'tarot__chelem tarot__action'));
        }

        this.actions.replaceChildren(...buttons);
        this.actions.classList.toggle('tarot__actions--visible', buttons.length > 0);
    }

    // Scores de chacun, du meilleur au moins bon
    renderScore() {
        const { deal, deals, lastTrick } = this.live;
        const scores = this.live.scores ?? this.view.players.map(() => 0);
        const order = this.view.players.map((player) => player.position).sort((a, b) => scores[b] - scores[a]);

        this.score.querySelector('.tarot__deal').textContent = String(Math.min(deal ?? 1, deals ?? 1));
        this.score.querySelector('.tarot__scores').replaceChildren(...order.map((position) => {
            const item = document.createElement('li');
            const name = document.createElement('span');
            const points = document.createElement('b');

            item.className = 'tarot__score-row';
            item.classList.toggle('tarot__score-row--me', position === this.me);
            name.className = 'tarot__score-name';
            name.textContent = this.player(position).pseudo;
            points.className = 'tarot__score-points';
            points.textContent = String(scores[position]);
            item.append(name, points);

            return item;
        }));

        this.lastTrickToggle.disabled = lastTrick.length === 0;

        if (lastTrick.length === 0) {
            this.toggleLastTrick(false);
        } else if (this.lastTrick.classList.contains('last-trick--open')) {
            this.fillLastTrick();
        }
    }

    timerSeconds(phase) {
        if (phase === 'encheres') {
            return this.view.bidSeconds ?? this.view.turnSeconds;
        }

        // L'écart : le double du temps d'un tour
        return phase === 'chien' ? 2 * this.view.turnSeconds : this.view.turnSeconds;
    }

    usesGauge(phase) {
        return phase === 'encheres';
    }

    // ---------- Actions du joueur ----------

    onClick(event) {
        const target = event.target;
        const bid = target.closest('.tarot__bid');
        const call = target.closest('.tarot__call');
        const card = target.closest('.hand__card');

        if (bid && !this.busy) {
            this.send('encheres', { contract: bid.dataset.contract ?? null });
        } else if (call && !this.busy) {
            this.send('appel', { card: call.dataset.card });
        } else if (card && this.isDiscarding() && card.classList.contains('hand__card--playable')) {
            this.toggleDiscard(card.dataset.card);
        } else if (target.closest('.tarot__discard') && !this.busy && this.selected.size === this.dogSize) {
            const cards = [...this.selected];
            this.selected.clear();
            this.send('ecart', { cards });
        } else if (target.closest('.tarot__chelem') && !this.busy) {
            this.send('chelem', {});
        } else if (target.closest('.tarot__poignee') && !this.busy) {
            this.send('poignee', { poignee: target.closest('.tarot__poignee').dataset.poignee });
        } else if (target.closest('.belote__tool--rules, .belote__rules-close, .belote__quit, .belote__leave-stay, .belote__leave-confirm, .belote__tool--fullscreen, .last-trick__toggle, .score__head')
            || (card && !this.isDiscarding())) {
            super.onClick(event);
        }
    }

    toggleDiscard(code) {
        if (this.selected.has(code)) {
            this.selected.delete(code);
        } else if (this.selected.size < this.dogSize) {
            this.selected.add(code);
        }

        this.renderHand();
        this.renderBidding();
    }

    async send(action, body) {
        this.renderDeclare();

        return super.send(action, body);
    }

    // ---------- Événements (animations) ----------

    async apply(event) {
        const handlers = {
            dealt: () => this.onDealt(event),
            bid: () => this.onBid(event),
            contract: () => this.onContract(event),
            called: () => this.onCalled(event),
            partner: async () => {
                this.live.partner = event.alone ? null : event.position;
                this.showToast(event.alone ? `${this.player(event.taker).pseudo} joue seul` : `${this.player(event.position).pseudo} est le partenaire de ${this.player(event.taker).pseudo}`, 2600);
                this.renderPlayers();
                await wait(800);
            },
            discarded: () => this.onDiscarded(event),
            chelem: async () => { this.showToast(`${this.player(event.position).pseudo} annonce le chelem !`, 2400); await wait(900); },
            poignee: () => this.onPoignee(event),
            redeal: async () => {
                this.showToast(event.reason === 'petit-sec' ? 'Petit sec : la donne est annulée' : 'Personne ne prend : on redistribue', 2200);
                await wait(900);
            },
            played: () => this.onPlayed(event),
            trick: () => this.onTrick(event),
            dealEnd: () => this.onDealEnd(event),
            gameEnd: async () => {},
            left: async () => { this.showToast(`${event.pseudo} a quitté la partie : un robot prend sa place`, 2600); await wait(600); },
        };

        await handlers[event.type]?.();
    }

    async onDealt(event) {
        Object.assign(this.live, {
            deal: event.deal, dealer: event.dealer, phase: 'encheres', taker: null, contract: null, contracts: [], dog: [], called: null, partner: null,
            callable: [], chelemPossible: false, poigneesPossible: [], trick: [], hand: [], counts: this.view.players.map(() => 0), turn: this.nextPosition(event.dealer),
        });
        this.selected.clear();
        this.dealing = true;
        this.render();
        // 3 par 3 (4 par 4 à 3 joueurs)
        const packet = this.count === 3 ? 4 : 3;
        await this.dealFlights(event.dealer, Array(event.cards.length / packet).fill(packet), event.counts, event.cards);
        this.dealing = false;
        this.renderBidding();
        await wait(250);
    }

    // Première ouverture de la page : on rejoue la distribution (3 cartes par 3)
    async introDeal() {
        const key = `tarot-intro-${this.view.id}`;

        try {
            if (window.sessionStorage.getItem(key) || this.view.deal !== 1 || this.view.phase !== 'encheres') {
                return;
            }

            window.sessionStorage.setItem(key, '1');
        } catch {
            return;
        }

        this.busy = true;
        this.dealing = true;
        const { hand, counts } = this.live;
        this.live.hand = [];
        this.live.counts = this.view.players.map(() => 0);
        this.render();
        await this.dealFlights(this.view.dealer, null, counts, hand);
        this.dealing = false;
        this.busy = false;
        this.render();
    }

    async onBid(event) {
        this.say(event.position, event.contract ? CONTRACTS[event.contract] : 'Je passe');

        if (event.contract) {
            Object.assign(this.live, { contract: event.contract, taker: event.position });
        }

        this.live.turn = this.nextPosition(event.position);
        this.renderPlayers();
        this.renderBidding();
        // Même rythme sur tous les écrans de la table (le sien compris)
        await wait(BID_PAUSE);
    }

    async onContract(event) {
        const name = event.taker === this.me ? 'Vous prenez' : `${this.player(event.taker).pseudo} prend`;
        Object.assign(this.live, { taker: event.taker, contract: event.contract });
        this.showToast(`${name} : ${CONTRACTS[event.contract].toLowerCase()}`, 2200);

        await this.showDog(event);
    }

    // À 5 joueurs, après l'appel : le chien (petite, garde) ou le jeu
    async showDog(event) {
        if (this.count === 5 && event.type === 'contract') {
            Object.assign(this.live, { phase: 'appel', turn: event.taker });
            this.render();
            await wait(500);

            return;
        }

        if (event.dog) {
            // Le chien est montré à tous au centre, puis rejoint le preneur
            Object.assign(this.live, { phase: 'chien', dog: event.dog, turn: event.taker });
            this.render();
            await wait(2200);
        } else {
            Object.assign(this.live, { phase: 'jeu', turn: this.nextPosition(this.live.dealer) });
            this.render();
            await wait(700);
        }
    }

    async onCalled(event) {
        this.live.called = event.card;
        this.showToast(`${event.taker === this.me ? 'Vous appelez' : `${this.player(event.taker).pseudo} appelle`} : ${this.cardName(event.card)}`, 2400);
        await this.showDog(event);
    }

    async onDiscarded(event) {
        const trumps = event.trumps ?? [];
        this.live.phase = 'jeu';
        this.live.dog = [];
        this.showToast(trumps.length > 0 ? `Écart fait, avec ${trumps.length} atout${trumps.length > 1 ? 's' : ''} montré${trumps.length > 1 ? 's' : ''} : ${trumps.map((code) => code.split('-')[1]).join(', ')}` : 'L’écart est fait', 2000);
        this.render();
        await wait(700);
    }

    async onPoignee(event) {
        this.say(event.position, POIGNEES[event.poignee]);
        const trumps = event.cards.map((code) => (code === 'excuse' ? 'Excuse' : code.split('-')[1])).join(', ');
        this.showToast(`${this.player(event.position).pseudo} montre : ${trumps}`, 3200);
        await wait(1400);
    }

    // Fin de donne : le résultat s'affiche au centre du tapis
    async onDealEnd(event) {
        const { result, scores } = event;
        const taker = this.player(result.taker).pseudo;
        const mine = result.taker === this.me;
        const gap = Math.abs(result.takerPoints - result.required);
        const bonus = [];

        if (result.petitAuBout !== null) {
            bonus.push(`Petit au bout ${result.petitAuBout === 0 ? 'pour le preneur' : 'pour la défense'}`);
        }

        if (Object.keys(result.poignees).length > 0) {
            bonus.push(Object.values(result.poignees).map((poignee) => POIGNEES[poignee]).join(', '));
        }

        if (result.chelem !== null) {
            bonus.push(result.chelem === 0 ? 'Chelem !' : 'Chelem de la défense !');
        } else if (result.chelemAnnounced) {
            bonus.push('Chelem annoncé, raté');
        }

        this.live.scores = scores;
        this.live.dealScores = [...(this.live.dealScores ?? []), result.scored];
        this.renderScore();

        const verdict = result.made ? `faite de ${gap}` : `chutée de ${gap}`;
        this.result.querySelector('.result__title').textContent = result.made
            ? (mine ? 'Contrat réussi !' : `${taker} a réussi`)
            : (mine ? 'Chuté !' : `${taker} a chuté`);
        this.result.querySelector('.result__taker').textContent = `${result.contractLabel} de ${taker} ${verdict} : ${String(result.takerPoints).replace('.', ',')} points pour ${result.required} (${result.bouts} bout${result.bouts > 1 ? 's' : ''})`;
        this.result.querySelector('.result__value').textContent = String(result.scored[this.me]);
        this.result.querySelector('.result__bonus').textContent = bonus.join(' · ');
        this.result.querySelector('.result__them').textContent = mine
            ? `Chaque défenseur marque ${result.scored.find((_, position) => position !== result.taker && position !== result.partner)} pts`
            : `${taker} marque ${result.scored[result.taker]} pts`;
        this.result.classList.add('result--visible');
        await wait(3000);
        this.result.classList.remove('result--visible');
        await wait(250);
    }

    showEnd(view) {
        const order = this.view.players.map((player) => player.position).sort((a, b) => view.scores[b] - view.scores[a]);
        const rank = order.indexOf(this.me) + 1;

        this.end.querySelector('.belote__end-title').textContent = view.winner === this.me ? 'Vous avez gagné !' : `${this.player(view.winner).pseudo} gagne la partie`;
        this.end.querySelector('.belote__end-score').textContent = `${rank}${rank === 1 ? 'er' : 'e'} · ${view.scores[this.me]} pts`;
        this.end.classList.add('belote__end--visible');
    }
}
