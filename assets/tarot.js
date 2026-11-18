// Page de partie de tarot : styles de la table (ceux de la belote, plus ceux du tarot) + JS (le serveur décide)
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/tarot.css';

if (document.querySelector('.tarot')) {
    import('./js/game/tarot/TarotTable.js').then(({ default: TarotTable }) => TarotTable.init());
}
