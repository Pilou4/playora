<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La coinche et la contrée deviennent des jeux à part (leurs salons, leurs réglages dans l'admin).
 * Les salons créés à l'étape 41 (jeu « belote », variante coinche ou contrée) passent dans leur jeu.
 * Aucune modification de structure.
 */
final class Version20261110200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coinche et contrée : les salons passent dans leur propre jeu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE game_room SET game = variant WHERE variant IN ('coinche', 'contree')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE game_room SET game = 'belote' WHERE game IN ('coinche', 'contree')");
    }
}
