<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\GameSetting;
use App\Enum\CardBack;
use App\Enum\CardSet;
use App\Enum\Carpet;
use App\Enum\Game;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Réglages d'un jeu (page admin). Les choix sont des boutons radio dessinés en aperçu dans le gabarit.
 */
final class GameSettingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('carpet', EnumType::class, [
                'class' => Carpet::class,
                'label' => 'Tapis',
                'invalid_message' => 'Ce tapis n’existe pas.',
                'expanded' => true,
                'choice_label' => static fn (Carpet $carpet): string => $carpet->label(),
                'constraints' => [new NotNull(message: 'Choisissez un tapis.')],
            ])
            ->add('cardSet', EnumType::class, [
                'class' => CardSet::class,
                // Cartes de tarot pour le tarot, jeux de 32 cartes pour la belote, la coinche et la contrée
                'choices' => CardSet::forGame($options['game']),
                'label' => 'Faces des cartes',
                'invalid_message' => 'Ce jeu de cartes n’existe pas.',
                'expanded' => true,
                'choice_label' => static fn (CardSet $set): string => $set->label(),
                'constraints' => [new NotNull(message: 'Choisissez un jeu de cartes.')],
            ])
            ->add('cardBack', EnumType::class, [
                'class' => CardBack::class,
                'label' => 'Dos des cartes',
                'invalid_message' => 'Ce dos de carte n’existe pas.',
                'expanded' => true,
                'choice_label' => static fn (CardBack $back): string => $back->label(),
                'constraints' => [new NotNull(message: 'Choisissez un dos de carte.')],
            ]);

        // Solitaire : 5 minutes par manche, pas de durée de tour ni d'enchères (les valeurs enregistrées restent)
        if (!$options['game']->hasTurns()) {
            return;
        }

        $builder
            ->add('turnSeconds', ChoiceType::class, [
                'label' => 'Durée d’un tour',
                'invalid_message' => 'Durée non autorisée.',
                'expanded' => true,
                'choices' => array_combine(
                    array_map(static fn (int $seconds): string => $seconds.' s', GameSetting::TURN_SECONDS_CHOICES),
                    GameSetting::TURN_SECONDS_CHOICES,
                ),
                'constraints' => [
                    new NotNull(message: 'Choisissez une durée.'),
                    new Choice(choices: GameSetting::TURN_SECONDS_CHOICES, message: 'Durée non autorisée.'),
                ],
            ]);

        // Rami : un temps par tour, pas d'enchères
        if (!$options['game']->hasBidding()) {
            return;
        }

        $builder
            ->add('bidSeconds', ChoiceType::class, [
                'label' => 'Durée pour prendre ou passer',
                'invalid_message' => 'Durée non autorisée.',
                'expanded' => true,
                'choices' => array_combine(
                    array_map(static fn (int $seconds): string => $seconds.' s', GameSetting::BID_SECONDS_CHOICES),
                    GameSetting::BID_SECONDS_CHOICES,
                ),
                'constraints' => [
                    new NotNull(message: 'Choisissez une durée.'),
                    new Choice(choices: GameSetting::BID_SECONDS_CHOICES, message: 'Durée non autorisée.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('game');
        $resolver->setAllowedTypes('game', Game::class);
        $resolver->setDefaults([
            'attr' => ['class' => 'admin-settings__form admin-form--ajax'],
            'csrf_message' => 'Le formulaire a expiré : merci de réessayer.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'reglages';
    }
}
