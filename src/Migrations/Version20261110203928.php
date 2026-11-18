<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110203928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tarot_deal (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, dealer INT NOT NULL, taker INT NOT NULL, contract VARCHAR(20) NOT NULL, made TINYINT NOT NULL, taker_half_points INT NOT NULL, bouts INT NOT NULL, value INT NOT NULL, scored JSON NOT NULL, petit_au_bout INT DEFAULT NULL, chelem TINYINT NOT NULL, played_at DATETIME NOT NULL, game_id INT NOT NULL, INDEX IDX_2CF17B55E48FD905 (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tarot_game (id INT AUTO_INCREMENT NOT NULL, state JSON NOT NULL, version INT NOT NULL, last_events JSON NOT NULL, scores JSON NOT NULL, winner_position INT DEFAULT NULL, turn_started_at DATETIME NOT NULL, room_id INT NOT NULL, UNIQUE INDEX UNIQ_EC248BCF54177093 (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tarot_deal ADD CONSTRAINT FK_2CF17B55E48FD905 FOREIGN KEY (game_id) REFERENCES tarot_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tarot_game ADD CONSTRAINT FK_EC248BCF54177093 FOREIGN KEY (room_id) REFERENCES game_room (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tarot_deal DROP FOREIGN KEY FK_2CF17B55E48FD905');
        $this->addSql('ALTER TABLE tarot_game DROP FOREIGN KEY FK_EC248BCF54177093');
        $this->addSql('DROP TABLE tarot_deal');
        $this->addSql('DROP TABLE tarot_game');
    }
}
