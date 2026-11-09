<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class RegistrationFormType extends AbstractType
{
    public const string PSEUDO_PATTERN = '^[\\p{L}\\p{N}._-]+$';
    public const string PSEUDO_MESSAGE = 'Lettres, chiffres et . _ - uniquement.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'data-required-message' => 'Indiquez votre adresse e-mail.'],
                'constraints' => [
                    new NotBlank(message: 'Indiquez votre adresse e-mail.'),
                    new Email(message: 'Cette adresse e-mail n’est pas valide.'),
                    new Length(max: 180, maxMessage: 'Adresse e-mail trop longue.'),
                ],
            ])
            ->add('pseudo', TextType::class, [
                'label' => 'Pseudo',
                'help' => 'Visible par les autres joueurs, modifiable à tout moment.',
                'attr' => [
                    'autocomplete' => 'username',
                    'minlength' => 3,
                    'maxlength' => 20,
                    'data-required-message' => 'Choisissez un pseudo.',
                    'data-min-message' => 'Au moins 3 caractères.',
                    'data-pattern' => self::PSEUDO_PATTERN,
                    'data-pattern-message' => self::PSEUDO_MESSAGE,
                ],
                'constraints' => [
                    new NotBlank(message: 'Choisissez un pseudo.'),
                    new Length(min: 3, max: 20, minMessage: 'Au moins {{ limit }} caractères.', maxMessage: '{{ limit }} caractères maximum.'),
                    new Regex(pattern: '/'.self::PSEUDO_PATTERN.'/u', message: self::PSEUDO_MESSAGE),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'help' => PasswordPolicy::HELP,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'maxlength' => PasswordPolicy::MAX_LENGTH,
                    'data-required-message' => 'Choisissez un mot de passe.',
                    'data-rules' => 'password',
                ],
                'constraints' => PasswordPolicy::constraints(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['novalidate' => 'novalidate', 'class' => 'form form--validate'],
            'csrf_message' => 'Le formulaire a expiré : merci de réessayer.',
        ]);
    }
}
