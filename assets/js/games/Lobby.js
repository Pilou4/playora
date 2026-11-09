// Page des salons, entièrement en JS : l'état vient de l'API, Mercure prévient des changements,
// et toutes les actions partent sans recharger la page. Le serveur décide toujours.
const PRESENCE_DELAY = 20000;
const REFRESH_DELAY = 150;
const TOAST_DURATION = 4000;

export default class Lobby {
    static init(root = document) {
        root.querySelectorAll('.lobby').forEach((element) => new Lobby(element).start());
    }

    constructor(element) {
        const { dataset } = element;

        this.element = element;
        this.apiUrl = dataset.apiUrl;
        this.presenceUrl = dataset.presenceUrl;
        this.hubUrl = dataset.hubUrl;
        this.csrf = dataset.csrf;
        this.loginUrl = dataset.loginUrl;
        this.connected = dataset.connected === '1';
        this.state = JSON.parse(dataset.state);

        this.list = element.querySelector('.lobby__list');
        this.empty = element.querySelector('.lobby__empty--rooms');
        this.tabs = [...element.querySelectorAll('.tabs__tab')];
        this.roomsPanel = element.querySelector('#salons-liste');
        this.rulesPanel = element.querySelector('#regles');
        this.createButton = element.querySelector('.lobby__create');
        this.dialog = element.querySelector('.dialog');
        this.form = element.querySelector('.room-form');
        this.toast = element.querySelector('.toast');
        this.roomTemplate = element.querySelector('#modele-salon');
        this.seatTemplate = element.querySelector('#modele-place');

        this.filter = 'all';
        // Numéro de la dernière réponse affichée : une réponse plus ancienne arrivée en retard est ignorée
        this.lastRequest = 0;
        this.lastShown = 0;
        this.busy = false;
        this.refreshTimer = null;
        this.toastTimer = null;
        this.presenceTimer = null;

        this.onClick = this.onClick.bind(this);
        this.onCreate = this.onCreate.bind(this);
        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        this.scheduleRefresh = this.scheduleRefresh.bind(this);
        this.refresh = this.refresh.bind(this);
        this.sendPresence = this.sendPresence.bind(this);
    }

    start() {
        this.element.addEventListener('click', this.onClick);
        this.form.addEventListener('submit', this.onCreate);
        document.addEventListener('visibilitychange', this.onVisibilityChange);

        if (this.hubUrl && 'EventSource' in window) {
            const source = new EventSource(this.hubUrl);
            source.addEventListener('message', this.scheduleRefresh);
            // Après une coupure, Mercure se reconnecte seul : on recharge l'état manqué
            source.addEventListener('open', this.scheduleRefresh);
        }

        this.render();
    }

    // ---------- Affichage ----------

    render() {
        const { rooms, mine } = this.state;

        if (mine?.status === 'playing') {
            window.location.assign(mine.playUrl);

            return;
        }

        const visible = rooms.filter((room) => this.matches(room));
        const items = [...(mine ? [this.roomElement(mine, true)] : []), ...visible.map((room) => this.roomElement(room, false))];

        this.list.replaceChildren(...items);
        this.empty.hidden = items.length > 0;
        // Caché sans être retiré : la barre ne bouge pas
        this.createButton.classList.toggle('lobby__create--hidden', Boolean(mine));
        this.updateCounts(rooms);
        this.updatePresence(Boolean(mine));
    }

    matches(room) {
        if (this.filter === 'sans-annonces') {
            return !room.announcements;
        }

        return this.filter === 'all' || this.filter === 'regles' || String(room.targetPoints) === this.filter;
    }

    updateCounts(rooms) {
        this.tabs.forEach((tab) => {
            const count = tab.querySelector('.tabs__count');

            if (count) {
                const previous = this.filter;
                this.filter = tab.dataset.filter;
                count.textContent = String(rooms.filter((room) => this.matches(room)).length);
                this.filter = previous;
            }
        });
    }

    roomElement(room, mine) {
        const element = this.roomTemplate.content.firstElementChild.cloneNode(true);

        element.classList.toggle('room--mine', mine);
        element.querySelector('.room__points').textContent = `${room.targetPoints} pts`;
        element.querySelector('.room__rounds').textContent = room.rounds > 1 ? `${room.rounds} manches` : '1 manche';
        element.querySelector('.room__announcements').textContent = room.announcements ? 'Annonces' : 'Sans annonces';
        element.querySelector('.room__seats').replaceChildren(
            ...room.seats.map((player, index) => this.seatElement(player, room.id, index + 1, mine)),
        );

        return element;
    }

    // Sous chaque place : « Jouer » si elle est libre, « Quitter » sous la sienne, rien sinon
    seatElement(player, room, position, mine) {
        const element = this.seatTemplate.content.firstElementChild.cloneNode(true);
        const avatar = element.querySelector('.seat__avatar');
        const name = element.querySelector('.seat__name');
        const button = element.querySelector('.seat__button');

        button.dataset.room = String(room);

        if (player === null) {
            element.classList.add('seat--free');
            name.textContent = 'Place libre';
            button.textContent = 'Jouer';
            button.dataset.action = 'join';
            button.dataset.position = String(position);
            button.setAttribute('aria-label', `Jouer à la place ${position}`);
            // Déjà assis ailleurs : on ne peut prendre une place qu'après avoir quitté la sienne
            button.disabled = Boolean(this.state.mine);
            // Dans son propre salon, on attend : le bouton reste là (invisible) pour que rien ne bouge,
            // sauf en mode test où il sert à ajouter un robot
            if (mine && this.state.bots) {
                button.textContent = '+ Robot';
                button.dataset.action = 'bot';
                button.setAttribute('aria-label', `Ajouter un robot à la place ${position}`);
                button.disabled = false;
            } else {
                button.classList.toggle('seat__button--hidden', mine);
            }
        } else {
            if (player.avatar) {
                const image = document.createElement('img');
                image.className = 'avatar__image';
                image.src = player.avatar;
                image.alt = '';
                image.width = 44;
                image.height = 44;
                avatar.replaceChildren(image);
            } else {
                avatar.classList.add(`avatar--${player.color}`);
                avatar.textContent = player.pseudo.charAt(0).toUpperCase();
            }
            name.textContent = player.pseudo;
            button.textContent = 'Quitter';
            button.dataset.action = 'leave';
            button.classList.add('seat__button--leave');
            button.classList.toggle('seat__button--hidden', !player.me);
        }

        if (button.classList.contains('seat__button--hidden')) {
            button.tabIndex = -1;
            button.setAttribute('aria-hidden', 'true');
        }

        return element;
    }

    // ---------- Actions ----------

    onClick(event) {
        const tab = event.target.closest('.tabs__tab');
        const action = event.target.closest('.seat__button');

        if (tab) {
            this.selectTab(tab);
        } else if (action) {
            this.act(action);
        } else if (event.target.closest('.lobby__create')) {
            this.openDialog();
        } else if (event.target.closest('.dialog__close')) {
            this.dialog.close();
        }
    }

    selectTab(selected) {
        this.filter = selected.dataset.filter;
        this.tabs.forEach((tab) => {
            const active = tab === selected;
            tab.classList.toggle('tabs__tab--active', active);
            tab.setAttribute('aria-selected', String(active));
        });

        const rules = this.filter === 'regles';
        this.rulesPanel.hidden = !rules;
        this.roomsPanel.hidden = rules;

        if (!rules) {
            this.render();
        }
    }

    openDialog() {
        if (!this.connected) {
            window.location.assign(this.loginUrl);

            return;
        }

        this.dialog.showModal();
    }

    async act(button) {
        if (!this.connected) {
            window.location.assign(this.loginUrl);

            return;
        }

        const { room, position, action } = button.dataset;

        if (action === 'leave') {
            await this.send(`${this.apiUrl}/${room}/joueurs`, 'DELETE', null, button);
        } else if (action === 'bot') {
            await this.send(`${this.apiUrl}/${room}/places/${position}/robot`, 'POST', null, button);
        } else {
            await this.send(`${this.apiUrl}/${room}/places/${position}`, 'POST', null, button);
        }
    }

    async onCreate(event) {
        event.preventDefault();
        const data = new FormData(this.form);
        const body = {
            targetPoints: Number(data.get('targetPoints')),
            announcements: data.get('announcements') === 'on',
        };

        if (await this.send(this.apiUrl, 'POST', body, this.form.querySelector('.dialog__submit'))) {
            this.dialog.close();
        }
    }

    async send(url, method, body, button) {
        if (this.busy) {
            return false;
        }

        this.busy = true;
        button.disabled = true;
        const request = ++this.lastRequest;

        try {
            const response = await fetch(url, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: body === null ? null : JSON.stringify(body),
            });

            if (response.status === 401) {
                window.location.assign(this.loginUrl);

                return false;
            }

            const payload = await response.json();
            this.show(request, response.ok ? payload : payload.state);

            if (!response.ok) {
                this.showToast(payload.detail ?? 'L’action n’a pas pu aboutir.');
            }

            return response.ok;
        } catch {
            this.showToast('Connexion perdue : réessayez dans un instant.');

            return false;
        } finally {
            this.busy = false;
            button.disabled = false;
        }
    }

    // ---------- Mises à jour ----------

    scheduleRefresh() {
        window.clearTimeout(this.refreshTimer);
        this.refreshTimer = window.setTimeout(this.refresh, REFRESH_DELAY);
    }

    async refresh() {
        const request = ++this.lastRequest;

        try {
            const response = await fetch(this.apiUrl, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                this.show(request, await response.json());
            }
        } catch {
            // Réseau coupé : la reconnexion de Mercure relancera le chargement
        }
    }

    show(request, state) {
        if (!state || request < this.lastShown) {
            return;
        }

        this.lastShown = request;
        this.state = state;

        if (!this.rulesPanel.hidden) {
            this.updateCounts(state.rooms);

            return;
        }

        this.render();
    }

    onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            this.refresh();

            if (this.state.mine) {
                this.sendPresence();
            }
        }
    }

    // Tant que le joueur a un onglet ouvert sur cette page, sa place reste à lui
    updatePresence(seated) {
        if (seated && this.presenceTimer === null) {
            this.presenceTimer = window.setInterval(this.sendPresence, PRESENCE_DELAY);
        } else if (!seated && this.presenceTimer !== null) {
            window.clearInterval(this.presenceTimer);
            this.presenceTimer = null;
        }
    }

    sendPresence() {
        fetch(this.presenceUrl, { method: 'POST', headers: { 'X-CSRF-Token': this.csrf } }).catch(() => {});
    }

    showToast(message) {
        const item = document.createElement('p');
        item.className = 'toast__message toast__message--visible';
        item.textContent = message;
        this.toast.replaceChildren(item);

        window.clearTimeout(this.toastTimer);
        this.toastTimer = window.setTimeout(() => item.classList.remove('toast__message--visible'), TOAST_DURATION);
    }
}
