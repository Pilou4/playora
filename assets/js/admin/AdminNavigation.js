// Administration : les liens (menu, tris, pages, détails) changent la page sans la recharger.
// Seul le contenu est remplacé : le menu reste où il est (il ne remonte plus en haut), le lien de la page s'allume aussitôt,
// la feuille pâlit pendant le chargement puis la nouvelle apparaît en fondu. Précédent / Suivant du navigateur marchent.
// En cas de souci (page d'erreur, session expirée, connexion perdue), la page se charge normalement.
import AdminFlash from './AdminFlash.js';
import AdminPage from './AdminPage.js';

const SCROLL_KEY = 'admin-nav-scroll';

export default class AdminNavigation {
    static init() {
        const admin = document.querySelector('.admin');

        if (admin) {
            new AdminNavigation(admin).start();
        }
    }

    constructor(admin) {
        this.admin = admin;
        this.nav = admin.querySelector('.admin__nav');
        this.scroller = admin.querySelector('.admin__nav-scroll') ?? this.nav;
        this.skipLink = document.querySelector('.skip-link');
        // Les adresses de l'admin commencent comme celle du tableau de bord (/admin/…)
        const dashboard = this.nav.querySelector('.admin__link')?.pathname ?? '/admin';
        this.prefix = `/${dashboard.split('/')[1]}`;
        this.controller = null;
        this.onClick = this.onClick.bind(this);
        this.onPopState = this.onPopState.bind(this);
        this.onPageHide = this.onPageHide.bind(this);
    }

    start() {
        this.admin.addEventListener('click', this.onClick);
        window.addEventListener('popstate', this.onPopState);
        window.addEventListener('pagehide', this.onPageHide);
        window.history.replaceState({ admin: true }, '', window.location.href);
        this.restoreScroll();
        this.revealActive();
    }

    onClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a');

        if (!link || link.target || link.hasAttribute('download')) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (url.origin !== window.location.origin || !this.isAdmin(url)) {
            return;
        }

        // Lien vers une ancre de la même page : le navigateur s'en charge
        if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) {
            return;
        }

        event.preventDefault();

        if (this.nav.contains(link)) {
            this.markActive(link);
        }

        // Tri ou page suivante d'un même tableau : on garde la position dans la page
        this.go(url.href, { push: true, keepScroll: url.pathname === window.location.pathname });
    }

    onPopState(event) {
        if (event.state?.admin) {
            this.go(window.location.href, { push: false, keepScroll: false });
        }
    }

    onPageHide() {
        try {
            window.sessionStorage.setItem(SCROLL_KEY, String(this.scroller.scrollTop));
        } catch {
            // Stockage indisponible (navigation privée…) : le menu repartira du haut
        }
    }

    isAdmin(url) {
        return url.pathname === this.prefix || url.pathname.startsWith(`${this.prefix}/`);
    }

    async go(href, { push, keepScroll }) {
        this.controller?.abort();
        const controller = new AbortController();
        this.controller = controller;
        this.sheet()?.classList.add('admin__sheet--loading');

        let response;
        let html;

        try {
            response = await fetch(href, { headers: { Accept: 'text/html' }, signal: controller.signal });
            html = await response.text();
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.assign(href);
            }

            return;
        }

        if (controller !== this.controller) {
            return;
        }

        this.controller = null;
        const url = response.url || href;
        const page = new DOMParser().parseFromString(html, 'text/html');
        const main = page.querySelector('.admin__main');

        // Page d'erreur, connexion demandée… : chargement normal
        if (!main || !this.isAdmin(new URL(url))) {
            window.location.assign(url);

            return;
        }

        this.swap(page, main, url, push, keepScroll);
    }

    swap(page, main, url, push, keepScroll) {
        const sheet = main.querySelector('.admin__sheet');

        if (sheet) {
            sheet.classList.add('admin__sheet--entering');
            sheet.addEventListener('animationend', () => sheet.classList.remove('admin__sheet--entering'), { once: true });
        }

        this.admin.querySelector('.admin__main').replaceWith(main);
        document.title = page.title;

        if (this.skipLink) {
            this.skipLink.href = `#${main.id}`;
        }

        if (push && url !== window.location.href) {
            window.history.pushState({ admin: true }, '', url);
        } else if (push) {
            window.history.replaceState({ admin: true }, '', url);
        }

        this.syncNav(page);
        this.showFlashes(page);

        if (!keepScroll) {
            this.scrollToContent();
        }

        this.revealActive();
        this.focusTitle(main);
        AdminPage.start(main);
    }

    sheet() {
        return this.admin.querySelector('.admin__sheet');
    }

    // Le lien cliqué s'allume tout de suite, sans attendre la nouvelle page
    markActive(link) {
        this.nav.querySelectorAll('.admin__link').forEach((item) => {
            item.classList.toggle('admin__link--active', item === link);

            if (item !== link) {
                item.removeAttribute('aria-current');
            }
        });
    }

    // Le menu prend l'état de la nouvelle page : lien actif, pastilles « inactif »
    syncNav(page) {
        const links = [...this.nav.querySelectorAll('.admin__link')];
        const freshLinks = [...page.querySelectorAll('.admin__nav .admin__link')];
        const badges = [...this.nav.querySelectorAll('.admin__nav-badge')];
        const freshBadges = [...page.querySelectorAll('.admin__nav .admin__nav-badge')];

        if (links.length === freshLinks.length) {
            links.forEach((link, index) => {
                link.className = freshLinks[index].className;
                const current = freshLinks[index].getAttribute('aria-current');

                if (current) {
                    link.setAttribute('aria-current', current);
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        }

        if (badges.length === freshBadges.length) {
            badges.forEach((badge, index) => {
                badge.className = freshBadges[index].className;
            });
        }
    }

    // Message posé par le serveur sur la nouvelle page (enregistré, erreur)
    showFlashes(page) {
        page.querySelectorAll('.admin-flash__message').forEach((message) => {
            const text = message.querySelector('.admin-flash__text')?.textContent ?? '';
            AdminFlash.show(text.trim(), message.classList.contains('admin-flash__message--error') ? 'error' : 'success');
        });
    }

    // Page plus bas que le haut du contenu : on remonte au début du contenu (rien ne bouge si on y est déjà)
    scrollToContent() {
        const top = this.admin.getBoundingClientRect().top + window.scrollY;

        if (window.scrollY > top) {
            window.scrollTo({ top, behavior: 'instant' });
        }
    }

    restoreScroll() {
        try {
            const saved = Number(window.sessionStorage.getItem(SCROLL_KEY));

            if (saved > 0) {
                this.scroller.scrollTop = saved;
            }
        } catch {
            // Stockage indisponible : rien à reprendre
        }
    }

    // Le lien de la page reste visible dans le menu (en hauteur sur ordinateur, de côté sur téléphone)
    revealActive() {
        const link = this.scroller.querySelector('.admin__link--active');

        if (!link) {
            return;
        }

        const box = this.scroller.getBoundingClientRect();
        const item = link.getBoundingClientRect();

        if (this.scroller.scrollWidth > this.scroller.clientWidth) {
            if (item.left < box.left || item.right > box.right) {
                this.scroller.scrollLeft += item.left - box.left - (box.width - item.width) / 2;
            }

            return;
        }

        const bottom = Math.min(box.bottom, window.innerHeight);

        if (item.top < box.top || item.bottom > bottom) {
            this.scroller.scrollTop += item.top - box.top - (bottom - box.top - item.height) / 2;
        }
    }

    // Lecteurs d'écran et clavier : on arrive sur le titre de la nouvelle page
    focusTitle(main) {
        const title = main.querySelector('h1');

        if (title) {
            title.setAttribute('tabindex', '-1');
            title.focus({ preventScroll: true });
        }
    }
}
