<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ForgotPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail',
            'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'data-required-message' => 'Indiquez votre adresse e-mail.'],
            'constraints' => [
                new NotBlank(message: 'Indiquez votre adresse e-mail.'),
                new Email(message: 'Cette adresse e-mail n’est pas valide.'),
                new Length(max: 180, maxMessage: 'Adresse e-mail trop longue.'),
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
        return 'mot_de_passe_oublie';
    }
}
