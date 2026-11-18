<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Faces des cartes, choisies par l'admin pour chaque jeu. Le dos se choisit à part (CardBack).
 * - Belote, coinche, contrée : un dossier par jeu dans public/images/cartes/francais/ (faces par couleur, en SVG).
 * - Tarot : un dossier par jeu dans public/images/cartes/tarot/ (une image par carte : « coeur-roi.webp », « atout-21.webp »…).
 */
enum CardSet: string
{
    case Modern = 'moderne';
    case TarotNouveau = 'tarot-nouveau';
    case TarotModerne = 'tarot-moderne';

    public function label(): string
    {
        return match ($this) {
            self::Modern => 'Moderne',
            self::TarotNouveau => 'Tarot classique',
            self::TarotModerne => 'Tarot moderne',
        };
    }

    public function isTarot(): bool
    {
        return \in_array($this, [self::TarotNouveau, self::TarotModerne], true);
    }

    /** Dossier des images, à passer à asset(). */
    public function path(): string
    {
        return match ($this) {
            self::TarotNouveau => 'images/cartes/tarot/nouveau/',
            self::TarotModerne => 'images/cartes/tarot/moderne/',
            default => 'images/cartes/francais/'.$this->value.'/',
        };
    }

    /** Format des images de ce jeu. */
    public function extension(): string
    {
        return $this->isTarot() ? 'webp' : 'svg';
    }

    /**
     * Les jeux de cartes qui conviennent à un jeu (cartes de tarot pour le tarot, 32 cartes sinon).
     *
     * @return list<self>
     */
    public static function forGame(Game $game): array
    {
        return array_values(array_filter(self::cases(), static fn (self $set): bool => $set->isTarot() === $game->isTarot()));
    }

    /** Le premier jeu qui convient : Moderne pour la belote, Tarot classique pour le tarot (Tarot moderne au choix dans l'admin). */
    public static function defaultFor(Game $game): self
    {
        return self::forGame($game)[0];
    }
}
