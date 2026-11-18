// Administration : styles, puis messages, scripts de la page (chargés à la demande) et changement de page sans rechargement
import './styles/admin.css';
import AdminPage from './js/admin/AdminPage.js';

import('./js/admin/AdminFlash.js').then(({ default: AdminFlash }) => AdminFlash.init());

AdminPage.start(document);

if (document.querySelector('.admin__nav')) {
    import('./js/admin/AdminNavigation.js').then(({ default: AdminNavigation }) => AdminNavigation.init());
}
