<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveaux avatars « portrait » : les anciens codes (personnages et animaux) n'existent plus.
 * On les efface : chacun choisit son nouvel avatar à sa prochaine visite (page « Bienvenue »).
 * Aucune modification de structure.
 */
final class Version20261108040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avatars portrait : efface les anciens avatars (chacun en choisit un nouveau).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE profile SET avatar = NULL WHERE avatar IS NOT NULL AND avatar NOT LIKE 'portrait.%'");
    }

    public function down(Schema $schema): void
    {
        // Les anciens codes ne peuvent pas être retrouvés : rien à faire
    }
}
