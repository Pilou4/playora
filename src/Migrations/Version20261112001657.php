<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112001657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE huit_game (id INT AUTO_INCREMENT NOT NULL, state JSON NOT NULL, version INT NOT NULL, scores JSON NOT NULL, round INT NOT NULL, winner_position INT DEFAULT NULL, turn_started_at DATETIME NOT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_D3B3588D54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE huit_round (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, dealer INT NOT NULL, winner INT DEFAULT NULL, penalties JSON NOT NULL, played_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX IDX_B21942CBE48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE huit_game ADD CONSTRAINT FK_D3B3588D54177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE huit_round ADD CONSTRAINT FK_B21942CBE48FD905 FOREIGN KEY (game_id) REFERENCES huit_game (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE huit_game DROP FOREIGN KEY FK_D3B3588D54177093');
        $this->addSql('ALTER TABLE huit_round DROP FOREIGN KEY FK_B21942CBE48FD905');
        $this->addSql('DROP TABLE huit_game');
        $this->addSql('DROP TABLE huit_round');
    }
}
