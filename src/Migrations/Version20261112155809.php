<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112155809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE golf_game (id INT AUTO_INCREMENT NOT NULL, state JSON NOT NULL, version INT NOT NULL, scores JSON NOT NULL, hole INT NOT NULL, winner_position INT DEFAULT NULL, turn_started_at DATETIME NOT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_FD57368854177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE golf_hole (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, dealer INT NOT NULL, closer INT DEFAULT NULL, points JSON NOT NULL, played_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX IDX_B6B13A95E48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE golf_game ADD CONSTRAINT FK_FD57368854177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE golf_hole ADD CONSTRAINT FK_B6B13A95E48FD905 FOREIGN KEY (game_id) REFERENCES golf_game (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE golf_game DROP FOREIGN KEY FK_FD57368854177093');
        $this->addSql('ALTER TABLE golf_hole DROP FOREIGN KEY FK_B6B13A95E48FD905');
        $this->addSql('DROP TABLE golf_game');
        $this->addSql('DROP TABLE golf_hole');
    }
}
