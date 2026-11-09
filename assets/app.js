import './styles/app.css';

// Chargés seulement sur les pages qui en ont besoin
if (document.querySelector('.account-menu')) {
    import('./js/AccountMenu.js').then(({ default: AccountMenu }) => AccountMenu.init());
}

if (document.querySelector('.field__toggle')) {
    import('./js/PasswordToggle.js').then(({ default: PasswordToggle }) => PasswordToggle.init());
}

if (document.querySelector('.form--validate')) {
    import('./js/FormValidator.js').then(({ default: FormValidator }) => FormValidator.init());
}

if (document.querySelector('.auth__panel--waiting, .auth__panel--confirmed')) {
    import('./js/AccountConfirmation.js').then(({ default: AccountConfirmation }) => AccountConfirmation.init());
}
