// Page de partie de belote : styles de la table + JS (moteur côté serveur, ici seulement l'affichage)
import './styles/games.css';
import './styles/game/table.css';
import './styles/game/belote.css';

if (document.querySelector('.belote')) {
    import('./js/game/belote/Table.js').then(({ default: Table }) => Table.init());
}
