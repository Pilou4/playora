// Pages de jeu (salons, partie) : styles communs aux jeux + JS chargé à la demande
import './styles/games.css';
import './styles/game/prepare.css';
import './styles/duels.css';

if (document.querySelector('.lobby')) {
    import('./js/games/Lobby.js').then(({ default: Lobby }) => Lobby.init());
}

if (document.querySelector('.launch')) {
    import('./js/games/Launch.js').then(({ default: Launch }) => Launch.init());
}

if (document.querySelector('.prepare')) {
    import('./js/games/PrepareDuel.js').then(({ default: PrepareDuel }) => PrepareDuel.init());
}

if (document.querySelector('.duel-result')) {
    import('./js/games/DuelResult.js').then(({ default: DuelResult }) => DuelResult.init());
}
