<?php

declare(strict_types=1);

namespace App\Enum;

use App\Game\Belote\Variant;
use App\Game\Spider\SpiderLevel;

/**
 * Jeux proposés. La valeur sert dans l'adresse (/jeux/belote, /jeux/coinche…) et en base.
 *
 * Belote, coinche et contrée sont trois jeux pour le joueur (chacun ses salons et ses réglages dans l'admin),
 * joués par le même moteur de belote avec la variante du jeu. Le tarot a son propre moteur ;
 * une partie s'y joue en un nombre de donnes (et non en points).
 * Les jeux de patience (solitaire et les trois Spider) se jouent de 2 à 5 joueurs, en une manche :
 * la même donne pour tous, le meilleur score l'emporte.
 */
enum Game: string
{
    case Belote = 'belote';
    case Coinche = 'coinche';
    case Contree = 'contree';
    case Tarot = 'tarot';
    case Solitaire = 'solitaire';
    case SolitaireHarmonie = 'solitaire-harmonie';
    case SpiderBeginner = 'spider-debutant';
    case Spider = 'spider';
    case SpiderPro = 'spider-pro';
    case Rami = 'rami';
    case HuitAmericain = 'huit-americain';
    case Golf = 'golf';
    case Freecell = 'freecell';
    case Pyramide = 'pyramide';
    case GolfSolitaire = 'golf-solitaire';
    case President = 'president';

    public function label(): string
    {
        return $this->variant()?->label() ?? $this->spiderLevel()?->label() ?? match ($this) {
            self::Solitaire => 'Solitaire',
            self::SolitaireHarmonie => 'Solitaire harmonie',
            self::Rami => 'Rami',
            self::HuitAmericain => '8 américain',
            self::Golf => 'Golf',
            self::Freecell => 'FreeCell',
            self::Pyramide => 'Pyramide',
            self::GolfSolitaire => 'Golf solitaire',
            self::President => 'Président',
            default => 'Tarot',
        };
    }

    public function playerCount(): int
    {
        return $this->isPatience() ? 2 : 4;
    }

    /**
     * Nombres de joueurs proposés à la création du salon.
     *
     * @return list<int>
     */
    public function playerCountChoices(): array
    {
        return match ($this) {
            self::Tarot => [3, 4, 5],
            self::Rami, self::HuitAmericain, self::Golf => [2, 3, 4, 5, 6],
            self::President => [3, 4, 5, 6, 7],
            default => $this->isPatience() ? [2, 3, 4, 5] : [$this->playerCount()],
        };
    }

    /** Variante du moteur de belote utilisée par ce jeu (null pour le tarot et les jeux de patience). */
    public function variant(): ?Variant
    {
        return match ($this) {
            self::Belote => Variant::Classic,
            self::Coinche => Variant::Coinche,
            self::Contree => Variant::Contree,
            default => null,
        };
    }

    public function isTarot(): bool
    {
        return self::Tarot === $this;
    }

    public function isRami(): bool
    {
        return self::Rami === $this;
    }

    public function isHuit(): bool
    {
        return self::HuitAmericain === $this;
    }

    public function isGolf(): bool
    {
        return self::Golf === $this;
    }

    public function isPresident(): bool
    {
        return self::President === $this;
    }

    /** Rami, 8 américain, Golf et Président : chacun pour soi, en plusieurs manches (ou trous). */
    public function playsInRounds(): bool
    {
        return $this->hasPenaltyRounds() || $this->isGolf() || $this->isPresident();
    }

    /** Rami et 8 américain : chacun pour soi, en manches ; les cartes restées en main comptent, le moins de points gagne. */
    public function hasPenaltyRounds(): bool
    {
        return $this->isRami() || $this->isHuit();
    }

    /** Enchères (belote, coinche, contrée, tarot) : leur durée se règle dans l'admin, à part du temps d'un tour. */
    public function hasBidding(): bool
    {
        return null !== $this->variant() || $this->isTarot();
    }

    /** Solitaire (3 cartes au talon) et Solitaire harmonie (une carte) */
    public function isSolitaire(): bool
    {
        return self::Solitaire === $this || self::SolitaireHarmonie === $this;
    }

    /** Cartes retournées à chaque clic sur le talon du solitaire (null pour les autres jeux). */
    public function solitaireDraw(): ?int
    {
        return match ($this) {
            self::Solitaire => 3,
            self::SolitaireHarmonie => 1,
            default => null,
        };
    }

    /** Jeu de patience (solitaire, Spider, FreeCell, Pyramide, Golf solitaire) : chacun sa grille, la même donne pour tous, en 5 minutes. */
    public function isPatience(): bool
    {
        return $this->isSolitaire() || $this->isSpider() || $this->isCardPatience();
    }

    /** FreeCell, Pyramide et Golf solitaire : une table et une API communes (CardPatience…). */
    public function isCardPatience(): bool
    {
        return \in_array($this, [self::Freecell, self::Pyramide, self::GolfSolitaire], true);
    }

    public function isSpider(): bool
    {
        return null !== $this->spiderLevel();
    }

    /** Niveau des trois Spider (débutant, Spider, Pro) ; null pour les autres jeux. */
    public function spiderLevel(): ?SpiderLevel
    {
        return match ($this) {
            self::SpiderBeginner => SpiderLevel::Beginner,
            self::Spider => SpiderLevel::Classic,
            self::SpiderPro => SpiderLevel::Pro,
            default => null,
        };
    }

    /** Annonces (tierce, cinquante…) : belote, coinche et contrée seulement. */
    public function hasAnnouncements(): bool
    {
        return null !== $this->variant();
    }

    /** Durée d'un tour et des enchères réglables dans l'admin (les jeux de patience ont leurs 5 minutes). */
    public function hasTurns(): bool
    {
        return !$this->isPatience();
    }

    /**
     * Points à atteindre (belote, coinche, contrée, rami, 8 américain), nombre de donnes (tarot) ou de trous (Golf) ; les jeux de patience se jouent en une manche.
     *
     * @return list<int>
     */
    public function targetPointsChoices(): array
    {
        return $this->variant()?->targetPointsChoices() ?? match (true) {
            $this->isPatience() => [1],
            $this->hasPenaltyRounds() => [300, 500, 1000],
            $this->isGolf() => [9, 18],
            // Président : points à atteindre par joueur (partie courte, moyenne, longue)
            $this->isPresident() => [2, 3, 4],
            default => [4, 8, 12],
        };
    }

    public function defaultTargetPoints(): int
    {
        return $this->variant()?->defaultTargetPoints() ?? match (true) {
            $this->isRami() => 500,
            $this->isPresident() => 3,
            default => $this->targetPointsChoices()[0],
        };
    }

    /** « points », « donnes », « manches » ou « trous » : ce que compte la fin de partie. */
    public function targetUnit(): string
    {
        return match (true) {
            self::Tarot === $this => 'donnes',
            $this->isPresident() => 'points par joueur',
            $this->isGolf() => 'trous',
            $this->isPatience() => 'manches',
            default => 'points',
        };
    }

    /** Pour les étiquettes courtes : « 501 pts », « 4 donnes », « 3 manches ». */
    public function targetShort(): string
    {
        return match (true) {
            self::Tarot === $this => 'donnes',
            $this->isPresident() => 'pts/joueur',
            $this->isGolf() => 'trous',
            $this->isPatience() => 'manches',
            default => 'pts',
        };
    }

    /** Étiquette courte d'une fin de partie : « 501 pts », « 4 donnes », « 1 manche », « 3 manches ». */
    public function targetLabel(int $value): string
    {
        $short = $this->targetShort();

        return $value.' '.(1 === $value && str_ends_with($short, 's') ? substr($short, 0, -1) : $short);
    }

    /** Fin de partie en toutes lettres (fenêtre de création) : « 501 points », « 1 manche »… */
    public function targetLongLabel(int $value): string
    {
        $unit = $this->targetUnit();

        return $value.' '.(1 === $value ? substr($unit, 0, -1) : $unit);
    }

    /** Le jeu se joue en équipes (belote) ou chacun pour soi (tarot, solitaire). */
    public function hasTeams(): bool
    {
        return null !== $this->variant();
    }

    /** Présentation courte du déroulement d'une partie (page des salons, fenêtre de création). */
    public function summary(): string
    {
        return match (true) {
            self::Tarot === $this => 'De 3 à 5 joueurs, places tirées au sort, un preneur contre les autres à chaque donne',
            $this->isPatience() => 'De 2 à 5 joueurs : la même donne pour tous, le meilleur score l’emporte',
            $this->isRami() => 'De 2 à 6 joueurs, chacun pour soi : le moins de points de pénalité l’emporte',
            $this->isHuit() => 'De 2 à 6 joueurs : videz votre main le premier, le moins de points l’emporte',
            $this->isGolf() => 'De 2 à 6 joueurs, 6 cartes chacun : le moins de points après 9 ou 18 trous l’emporte',
            $this->isPresident() => 'De 3 à 7 joueurs, chacun pour soi : videz votre main le premier pour devenir Président',
            default => $this->playerCount().' joueurs, 2 équipes tirées au sort au début de la partie',
        };
    }
}
