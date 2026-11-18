// Page d'une partie de Spider : styles de la table (ceux de la belote et du solitaire : cadre, tapis, temps, joueurs)
// + ceux du Spider, et son JS
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';
import './styles/game/solitaire.css';
import './styles/game/spider.css';

if (document.querySelector('.spider')) {
    import('./js/game/spider/SpiderTable.js').then(({ default: SpiderTable }) => SpiderTable.init());
}
