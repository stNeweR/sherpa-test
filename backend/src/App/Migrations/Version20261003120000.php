<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Создание таблицы import_jobs для отслеживания асинхронного импорта.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_jobs (id VARCHAR(36) NOT NULL, file_name VARCHAR(255) NOT NULL, file_path VARCHAR(1024) NOT NULL, state VARCHAR(32) NOT NULL, processed INT DEFAULT 0 NOT NULL, imported INT DEFAULT 0 NOT NULL, updated INT DEFAULT 0 NOT NULL, failed INT DEFAULT 0 NOT NULL, error TEXT DEFAULT NULL, report JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_import_jobs_created_at ON import_jobs (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_jobs');
    }
}
