// Page d'une partie de rami : styles de la table (ceux de la belote : cadre, tapis, fenêtres) + ceux du rami, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/rami.css';

if (document.querySelector('.rami')) {
    import('./js/game/rami/RamiTable.js').then(({ default: RamiTable }) => RamiTable.init());
}
