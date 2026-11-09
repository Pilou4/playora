<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261105132144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game_room (id INT AUTO_INCREMENT NOT NULL, game VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, visibility VARCHAR(20) NOT NULL, invite_code VARCHAR(8) NOT NULL, player_count INT NOT NULL, rounds INT NOT NULL, target_points INT NOT NULL, announcements TINYINT NOT NULL, turn_seconds INT NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, host_id INT NOT NULL, INDEX idx_game_room_list (game, status, visibility), UNIQUE INDEX uniq_game_room_invite_code (invite_code), INDEX IDX_998A3DB71FB8D185 (host_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_room_seat (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, joined_at DATETIME DEFAULT NULL, room_id INT NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX uniq_game_room_seat_position (room_id, position), UNIQUE INDEX uniq_game_room_seat_user (room_id, user_id), INDEX IDX_8CFE1D0054177093 (room_id), INDEX IDX_8CFE1D00A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_room ADD CONSTRAINT FK_998A3DB71FB8D185 FOREIGN KEY (host_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE game_room_seat ADD CONSTRAINT FK_8CFE1D0054177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_room_seat ADD CONSTRAINT FK_8CFE1D00A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_room DROP FOREIGN KEY FK_998A3DB71FB8D185');
        $this->addSql('ALTER TABLE game_room_seat DROP FOREIGN KEY FK_8CFE1D0054177093');
        $this->addSql('ALTER TABLE game_room_seat DROP FOREIGN KEY FK_8CFE1D00A76ED395');
        $this->addSql('DROP TABLE game_room');
        $this->addSql('DROP TABLE game_room_seat');
    }
}
