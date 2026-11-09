<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Règles d'un nouveau mot de passe, communes à l'inscription et au changement de mot de passe.
 * Les mêmes règles sont vérifiées dans le navigateur (assets/js/FormValidator.js), pour le confort.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;
    public const string HELP = 'Au moins 12 caractères, avec une majuscule, une minuscule, un chiffre et un caractère spécial.';

    /**
     * @return list<Constraint>
     */
    public static function constraints(string $blankMessage = 'Choisissez un mot de passe.'): array
    {
        return [
            new NotBlank(message: $blankMessage),
            new Length(
                min: self::MIN_LENGTH,
                max: self::MAX_LENGTH,
                minMessage: 'Au moins {{ limit }} caractères.',
                maxMessage: '{{ limit }} caractères maximum.',
            ),
            new Regex(pattern: '/\p{Lu}/u', message: 'Ajoutez une majuscule.'),
            new Regex(pattern: '/\p{Ll}/u', message: 'Ajoutez une minuscule.'),
            new Regex(pattern: '/\d/', message: 'Ajoutez un chiffre.'),
            new Regex(pattern: '/[^\p{L}\p{N}]/u', message: 'Ajoutez un caractère spécial (! ? @ # …).'),
            // « faible » au sens de Symfony = environ 60 bits : refuse les répétitions (Aaaaaaaaaaa1!) ; « moyen » imposerait ~14 caractères
            new PasswordStrength(minScore: PasswordStrength::STRENGTH_WEAK, message: 'Mot de passe trop facile à deviner.'),
            // skipOnError : si le service de vérification est injoignable, on peut quand même continuer
            new NotCompromisedPassword(message: 'Mot de passe exposé dans une fuite de données.', skipOnError: true),
        ];
    }
}
