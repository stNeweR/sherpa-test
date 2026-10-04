<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Создание таблицы users для аутентификации по JWT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id SERIAL NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (LOWER(email))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
