// Vérification dans le navigateur, pour le confort : le serveur revérifie toujours tout.
const PASSWORD_RULES = [
    [(value) => value.length >= 12, 'Au moins 12 caractères.'],
    [(value) => /\p{Lu}/u.test(value), 'Ajoutez une majuscule.'],
    [(value) => /\p{Ll}/u.test(value), 'Ajoutez une minuscule.'],
    [(value) => /\d/.test(value), 'Ajoutez un chiffre.'],
    [(value) => /[^\p{L}\p{N}]/u.test(value), 'Ajoutez un caractère spécial (! ? @ # …).'],
];

export default class FormValidator {
    static init(root = document) {
        root.querySelectorAll('.form--validate').forEach((form) => new FormValidator(form));
    }

    constructor(form) {
        this.form = form;
        this.inputs = [...form.querySelectorAll('.field__input')];

        this.onSubmit = this.onSubmit.bind(this);
        this.onBlur = this.onBlur.bind(this);
        this.onInput = this.onInput.bind(this);

        this.form.addEventListener('submit', this.onSubmit);
        this.inputs.forEach((input) => {
            input.addEventListener('blur', this.onBlur);
            input.addEventListener('input', this.onInput);
        });
    }

    onSubmit(event) {
        const invalid = this.inputs.filter((input) => !this.check(input));

        if (invalid.length > 0) {
            event.preventDefault();
            invalid[0].focus();
        }
    }

    onBlur(event) {
        if (event.target.value !== '') {
            this.check(event.target);
        }
    }

    // Une fois en erreur, le message disparaît dès que la saisie devient correcte
    onInput(event) {
        if (event.target.closest('.field').classList.contains('field--error')) {
            this.check(event.target);
        }
    }

    check(input) {
        const field = input.closest('.field');
        const slot = field.querySelector('.field__error');
        const message = this.messageFor(input);

        field.classList.toggle('field--error', message !== '');
        slot.textContent = message;

        if (message === '') {
            input.removeAttribute('aria-invalid');
        } else {
            input.setAttribute('aria-invalid', 'true');
        }

        return message === '';
    }

    messageFor(input) {
        const value = input.type === 'password' ? input.value : input.value.trim();
        const { dataset } = input;

        if (value === '') {
            return input.required ? (dataset.requiredMessage ?? 'Ce champ est obligatoire.') : '';
        }

        if (input.type === 'email' && input.validity.typeMismatch) {
            return 'Cette adresse e-mail n’est pas valide.';
        }

        if (input.minLength > 0 && value.length < input.minLength) {
            return dataset.minMessage ?? `Au moins ${input.minLength} caractères.`;
        }

        if (dataset.pattern && !new RegExp(dataset.pattern, 'u').test(value)) {
            return dataset.patternMessage ?? 'Format incorrect.';
        }

        if (dataset.rules === 'password') {
            return PASSWORD_RULES.find(([isValid]) => !isValid(value))?.[1] ?? '';
        }

        // Confirmation : doit être identique au champ indiqué (ex. nouveau mot de passe)
        if (dataset.match && value !== (document.getElementById(dataset.match)?.value ?? '')) {
            return dataset.matchMessage ?? 'Les deux valeurs ne correspondent pas.';
        }

        return '';
    }
}
