<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the sponsor.in_carousel flag used by the footer sponsor carousel.
 *
 * NOTE: `doctrine:migrations:diff` also wanted
 * `ALTER TABLE sponsor ALTER name DROP NOT NULL` — that is PRE-EXISTING drift
 * between the live schema and the entity mapping (entity has allowed
 * nullable names since upstream; the prod column was never relaxed). It is
 * deliberately NOT part of this migration (out of scope, no code depends on
 * null names) — track it as its own issue.
 */
final class Version20261123220618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sponsor.in_carousel flag for the footer carousel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sponsor ADD in_carousel BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sponsor DROP in_carousel');
    }
}
