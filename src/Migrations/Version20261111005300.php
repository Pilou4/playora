<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111005300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE solitaire_board (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, round INT NOT NULL, state JSON NOT NULL, score INT NOT NULL, complete TINYINT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, game_id INT NOT NULL, UNIQUE INDEX solitaire_board_unique (game_id, position, round), INDEX IDX_EF4057C6E48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE solitaire_game (id INT AUTO_INCREMENT NOT NULL, round INT NOT NULL, rounds INT NOT NULL, version INT NOT NULL, finished TINYINT NOT NULL, winner_position INT DEFAULT NULL, created_at DATETIME NOT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_880B8BF254177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE solitaire_board ADD CONSTRAINT FK_EF4057C6E48FD905 FOREIGN KEY (game_id) REFERENCES solitaire_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE solitaire_game ADD CONSTRAINT FK_880B8BF254177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE solitaire_board DROP FOREIGN KEY FK_EF4057C6E48FD905');
        $this->addSql('ALTER TABLE solitaire_game DROP FOREIGN KEY FK_880B8BF254177093');
        $this->addSql('DROP TABLE solitaire_board');
        $this->addSql('DROP TABLE solitaire_game');
    }
}
