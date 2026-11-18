<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TarotGame;
use App\Game\Tarot\Poignee;
use App\Game\Tarot\TarotCard;
use App\Game\Tarot\TarotContract;
use App\Game\Tarot\TarotEngine;
use App\Game\Tarot\TarotPhase;
use App\Game\Tarot\TarotRules;
use App\Game\Tarot\TarotState;
use App\Game\Tarot\TrickCard;

/**
 * Ce qu'un joueur voit de la table de tarot : sa main seulement, le nombre de cartes des autres,
 * le pli, les enchères, le chien quand il est montré, les scores. « me » dit quelle place (0 à 3) est la sienne.
 */
final readonly class TarotView
{
    public function __construct(
        private BeloteView $belote,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(TarotGame $game, int $me): array
    {
        $state = $game->getState();
        $room = $game->getRoom();
        $engine = new TarotEngine();
        $codes = static fn (array $cards): array => array_map(static fn (TarotCard $card): string => $card->code(), $cards);
        $trick = static fn (array $trick): array => array_map(static fn (TrickCard $played): array => [$played->position, $played->card->code()], $trick);
        $myTurn = $state->turn === $me;

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $state->phase->value,
            'deal' => $state->dealNumber,
            'deals' => $state->totalDeals,
            'playerCount' => $state->players,
            // À 5 joueurs : la carte appelée, et le partenaire seulement quand on a le droit de le savoir
            'called' => $state->called?->code(),
            'partner' => $state->partnerRevealed() || $me === $state->partner ? $state->partner : null,
            'callable' => TarotPhase::Call === $state->phase && $myTurn ? $codes($engine->callable($state)) : [],
            'dogSize' => TarotRules::dogSize($state->players),
            'dealer' => $state->dealer,
            'turn' => $state->turn,
            'taker' => $state->taker,
            'contract' => $state->contract?->value,
            'bids' => (object) $state->bids,
            // Enchères possibles si c'est à moi de parler (plus fort que l'enchère en cours)
            'contracts' => TarotPhase::Bidding === $state->phase && $myTurn
                ? array_values(array_map(static fn (TarotContract $contract): string => $contract->value, array_filter(TarotContract::cases(), static fn (TarotContract $contract): bool => null === $state->contract || $contract->level() > $state->contract->level())))
                : [],
            'dog' => $codes($state->shownDog),
            'discarding' => TarotPhase::Dog === $state->phase && $myTurn,
            'discardable' => TarotPhase::Dog === $state->phase && $myTurn ? $this->discardable($state, $me) : [],
            'chelemAnnounced' => $state->chelemAnnounced,
            'chelemPossible' => $me === $state->taker && !$state->chelemAnnounced
                && (TarotPhase::Dog === $state->phase || (TarotPhase::Playing === $state->phase && [] === $state->history)),
            'poignees' => array_map(static fn (Poignee $poignee): string => $poignee->value, $state->poignees),
            'poigneesPossible' => array_map(static fn (Poignee $poignee): string => $poignee->value, $engine->poigneesFor($state, $me)),
            'trick' => $trick($state->trick),
            'lastTrick' => $trick($state->lastTrick),
            'hand' => $codes($state->hands[$me]),
            'playable' => $codes($state->playableCards($me)),
            'counts' => array_map('count', $state->hands),
            'scores' => $state->scores,
            'dealScores' => $state->dealScores,
            'winner' => $state->winner,
            'turnSeconds' => $room->getTurnSeconds(),
            'bidSeconds' => $room->getBidSeconds(),
            'secondsLeft' => round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'players' => $this->belote->players($room->getSeats()),
        ];
    }

    /**
     * Événements d'une action, sans les cartes des autres joueurs.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    public function events(array $events, int $me): array
    {
        return array_map(static function (array $event) use ($me): array {
            if ('dealt' === $event['type']) {
                $event['counts'] = array_map('count', $event['cards']);
                $event['cards'] = $event['cards'][$me] ?? [];
            }

            return $event;
        }, $events);
    }

    /**
     * Cartes que le preneur peut écarter : de couleur (sans les Rois) ; des atouts (sans les bouts)
     * seulement s'il n'a pas assez de cartes de couleur.
     *
     * @return list<string>
     */
    private function discardable(TarotState $state, int $me): array
    {
        [$plain, $trumps] = TarotRules::discardable($state->hands[$me]);
        $cards = \count($plain) >= TarotRules::dogSize($state->players) ? $plain : [...$plain, ...$trumps];

        return array_map(static fn (TarotCard $card): string => $card->code(), $cards);
    }
}
