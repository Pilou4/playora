<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106143019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE belote_deal (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, dealer INT NOT NULL, taker INT NOT NULL, trump VARCHAR(10) NOT NULL, outcome VARCHAR(10) NOT NULL, team1_card_points INT NOT NULL, team2_card_points INT NOT NULL, team1_scored INT NOT NULL, team2_scored INT NOT NULL, belote_team INT DEFAULT NULL, capot_team INT DEFAULT NULL, played_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX idx_belote_deal_game (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE belote_game (id INT AUTO_INCREMENT NOT NULL, state JSON NOT NULL, version INT NOT NULL, last_events JSON NOT NULL, team1_score INT NOT NULL, team2_score INT NOT NULL, winner_team INT DEFAULT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_1F5F4DDD54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_player (id INT AUTO_INCREMENT NOT NULL, bot TINYINT NOT NULL, position INT NOT NULL, team INT NOT NULL, tricks_won INT NOT NULL, points_collected INT NOT NULL, contracts_taken INT NOT NULL, contracts_made INT NOT NULL, team_score INT NOT NULL, won TINYINT DEFAULT NULL, room_id INT NOT NULL, user_id INT DEFAULT NULL, INDEX idx_game_player_user (user_id), UNIQUE INDEX uniq_game_player_position (room_id, position), INDEX IDX_E52CD7AD54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE belote_deal ADD CONSTRAINT FK_DF8ABD47E48FD905 FOREIGN KEY (game_id) REFERENCES belote_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE belote_game ADD CONSTRAINT FK_1F5F4DDD54177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7AD54177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7ADA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_room_seat ADD bot TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE belote_deal DROP FOREIGN KEY FK_DF8ABD47E48FD905');
        $this->addSql('ALTER TABLE belote_game DROP FOREIGN KEY FK_1F5F4DDD54177093');
        $this->addSql('ALTER TABLE game_player DROP FOREIGN KEY FK_E52CD7AD54177093');
        $this->addSql('ALTER TABLE game_player DROP FOREIGN KEY FK_E52CD7ADA76ED395');
        $this->addSql('DROP TABLE belote_deal');
        $this->addSql('DROP TABLE belote_game');
        $this->addSql('DROP TABLE game_player');
        $this->addSql('ALTER TABLE game_room_seat DROP bot');
    }
}
