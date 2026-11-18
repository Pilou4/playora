// Page d'une partie de FreeCell, de Pyramide ou de Golf solitaire : styles de la table (ceux de la belote et du
// solitaire : cadre, tapis bois, ticket du temps, fenêtres) + ceux de ces trois jeux, et le JS du jeu de la page
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/solitaire.css';
import './styles/game/patience.css';

const TABLES = {
    freecell: () => import('./js/game/patience/FreecellTable.js'),
    pyramide: () => import('./js/game/patience/PyramideTable.js'),
    'golf-solitaire': () => import('./js/game/patience/GolfSolitaireTable.js'),
};

document.querySelectorAll('.patience').forEach((element) => {
    TABLES[element.dataset.kind]?.().then(({ default: Table }) => new Table(element).start());
});
