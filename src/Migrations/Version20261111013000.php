<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le Tarot de Marseille est retiré : les salons et les réglages qui l'utilisaient passent au Tarot classique.
 * Aucune modification de structure.
 */
final class Version20261111013000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarot : le Tarot de Marseille est remplacé par le Tarot classique.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE game_room SET card_set = 'tarot-nouveau' WHERE card_set = 'tarot-marseille'");
        $this->addSql("UPDATE game_setting SET card_set = 'tarot-nouveau' WHERE card_set = 'tarot-marseille'");
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : le Tarot de Marseille n'existe plus dans le code.
    }
}
