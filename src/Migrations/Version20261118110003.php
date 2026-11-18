<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118110003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE president_game (id INT AUTO_INCREMENT NOT NULL, state JSON NOT NULL, version INT NOT NULL, scores JSON NOT NULL, round INT NOT NULL, winner_position INT DEFAULT NULL, turn_started_at DATETIME NOT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_712DB38F54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE president_round (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, finish_order JSON NOT NULL, points JSON NOT NULL, played_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX IDX_5CB5BD0CE48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE president_game ADD CONSTRAINT FK_712DB38F54177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE president_round ADD CONSTRAINT FK_5CB5BD0CE48FD905 FOREIGN KEY (game_id) REFERENCES president_game (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE president_game DROP FOREIGN KEY FK_712DB38F54177093');
        $this->addSql('ALTER TABLE president_round DROP FOREIGN KEY FK_5CB5BD0CE48FD905');
        $this->addSql('DROP TABLE president_game');
        $this->addSql('DROP TABLE president_round');
    }
}
