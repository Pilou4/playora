// Page d'un duel de solitaire : styles de la table (ceux de la belote : cadre, tapis, fenêtres) + ceux du solitaire, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/solitaire.css';

if (document.querySelector('.solitaire')) {
    import('./js/game/solitaire/SolitaireTable.js').then(({ default: SolitaireTable }) => SolitaireTable.init());
}
