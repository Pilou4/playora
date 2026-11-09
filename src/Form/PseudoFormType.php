<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Changer de pseudo : mêmes règles qu'à l'inscription.
 */
final class PseudoFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pseudo', TextType::class, [
            'label' => 'Pseudo',
            'help' => '3 à 20 caractères : lettres, chiffres et . _ - uniquement. Visible par les autres joueurs.',
            'attr' => [
                'autocomplete' => 'username',
                'minlength' => 3,
                'maxlength' => 20,
                'data-required-message' => 'Choisissez un pseudo.',
                'data-min-message' => 'Au moins 3 caractères.',
                'data-pattern' => RegistrationFormType::PSEUDO_PATTERN,
                'data-pattern-message' => RegistrationFormType::PSEUDO_MESSAGE,
            ],
            'constraints' => [
                new NotBlank(message: 'Choisissez un pseudo.'),
                new Length(min: 3, max: 20, minMessage: 'Au moins {{ limit }} caractères.', maxMessage: '{{ limit }} caractères maximum.'),
                new Regex(pattern: '/'.RegistrationFormType::PSEUDO_PATTERN.'/u', message: RegistrationFormType::PSEUDO_MESSAGE),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['novalidate' => 'novalidate', 'class' => 'form form--validate'],
            'csrf_message' => 'Le formulaire a expiré : merci de réessayer.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'profil';
    }
}
