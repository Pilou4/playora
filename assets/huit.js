// Page d'une partie de 8 américain : styles de la table (belote : cadre, tapis, fenêtres ; rami : joueurs, main, boutons)
// + ceux du 8 américain, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/rami.css';
import './styles/game/huit.css';

if (document.querySelector('.huit')) {
    import('./js/game/huit/HuitTable.js').then(({ default: HuitTable }) => HuitTable.init());
}
