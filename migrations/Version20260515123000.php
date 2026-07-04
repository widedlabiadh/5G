<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket_site table for multi-site workflows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS ticket_site (id SERIAL NOT NULL, ticket_id INT NOT NULL, site_name VARCHAR(120) NOT NULL, type_trans VARCHAR(50) DEFAULT NULL, service_name VARCHAR(80) DEFAULT NULL, max_trafic DOUBLE PRECISION DEFAULT NULL, source_site_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ticket_site_ticket ON ticket_site (ticket_id)');
        $this->addSql('ALTER TABLE ticket_site DROP CONSTRAINT IF EXISTS FK_30E67872700047D2');
        $this->addSql('ALTER TABLE ticket_site ADD CONSTRAINT FK_30E67872700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ticket_site');
    }
}
