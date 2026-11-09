<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ResetPasswordFormType extends AbstractType
{
    public const string MISMATCH = 'Les deux mots de passe ne correspondent pas.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'help' => PasswordPolicy::HELP,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'maxlength' => PasswordPolicy::MAX_LENGTH,
                    'data-required-message' => 'Choisissez un mot de passe.',
                    'data-rules' => 'password',
                ],
                'constraints' => PasswordPolicy::constraints(),
            ])
            ->add('confirmPassword', PasswordType::class, [
                'label' => 'Confirmez le mot de passe',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'maxlength' => PasswordPolicy::MAX_LENGTH,
                    'data-required-message' => 'Saisissez à nouveau le mot de passe.',
                    'data-match' => 'nouveau_mot_de_passe_plainPassword',
                    'data-match-message' => self::MISMATCH,
                ],
                'constraints' => [new NotBlank(message: 'Saisissez à nouveau le mot de passe.')],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $form = $event->getForm();
                $confirm = $form->get('confirmPassword');

                if ('' !== (string) $confirm->getData() && $form->get('plainPassword')->getData() !== $confirm->getData()) {
                    $confirm->addError(new FormError(self::MISMATCH));
                }
            });
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
        return 'nouveau_mot_de_passe';
    }
}
