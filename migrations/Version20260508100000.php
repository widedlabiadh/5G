<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add operational fields to managed_site for superuser site completion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS max_trafic NUMERIC(15, 4) DEFAULT NULL");
        $this->addSql("ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS type_trans VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS classification VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS latitude DOUBLE PRECISION DEFAULT NULL");
        $this->addSql("ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS longitude DOUBLE PRECISION DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE managed_site DROP COLUMN IF EXISTS longitude");
        $this->addSql("ALTER TABLE managed_site DROP COLUMN IF EXISTS latitude");
        $this->addSql("ALTER TABLE managed_site DROP COLUMN IF EXISTS classification");
        $this->addSql("ALTER TABLE managed_site DROP COLUMN IF EXISTS type_trans");
        $this->addSql("ALTER TABLE managed_site DROP COLUMN IF EXISTS max_trafic");
    }
}
