// Pages de jeu (salons, partie) : styles communs aux jeux + JS chargé à la demande
import './styles/games.css';

if (document.querySelector('.lobby')) {
    import('./js/games/Lobby.js').then(({ default: Lobby }) => Lobby.init());
}

if (document.querySelector('.launch')) {
    import('./js/games/Launch.js').then(({ default: Launch }) => Launch.init());
}
