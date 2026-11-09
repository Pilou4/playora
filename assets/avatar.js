// Page « Mon avatar » : styles + JS chargé à la demande
import './styles/avatar.css';

if (document.querySelector('.avatar-page')) {
    import('./js/avatar/AvatarEditor.js').then(({ default: AvatarEditor }) => AvatarEditor.init());
}
