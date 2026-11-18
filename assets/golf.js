// Page d'une partie de Golf : styles de la table (belote : cadre, tapis, fenêtres ; rami : joueurs, boutons, fin de trou)
// + ceux du Golf, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/rami.css';
import './styles/game/golf.css';

if (document.querySelector('.golf')) {
    import('./js/game/golf/GolfTable.js').then(({ default: GolfTable }) => GolfTable.init());
}
