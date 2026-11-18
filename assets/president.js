// Page d'une partie de Président : styles de la table (belote : cadre, tapis, fenêtres ; rami : joueurs, main, boutons)
// + ceux du Président, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/rami.css';
import './styles/game/president.css';

if (document.querySelector('.president')) {
    import('./js/game/president/PresidentTable.js').then(({ default: PresidentTable }) => PresidentTable.init());
}
