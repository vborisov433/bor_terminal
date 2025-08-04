<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250804141657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE market_analysis (id INT AUTO_INCREMENT NOT NULL, news_item_id INT NOT NULL, market VARCHAR(255) NOT NULL, sentiment VARCHAR(255) NOT NULL, magnitude INT NOT NULL, reason LONGTEXT NOT NULL, keywords JSON NOT NULL COMMENT \'(DC2Type:json)\', categories JSON NOT NULL COMMENT \'(DC2Type:json)\', INDEX IDX_ED82D2F8458B4EB8 (news_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE news_article_info (id INT AUTO_INCREMENT NOT NULL, news_item_id INT NOT NULL, has_market_impact TINYINT(1) NOT NULL, title_headline VARCHAR(255) DEFAULT NULL, news_surprise_index INT DEFAULT NULL, economy_impact INT DEFAULT NULL, macro_keyword_heatmap JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', summary LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_9D81DFE1458B4EB8 (news_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE market_analysis ADD CONSTRAINT FK_ED82D2F8458B4EB8 FOREIGN KEY (news_item_id) REFERENCES news_item (id)');
        $this->addSql('ALTER TABLE news_article_info ADD CONSTRAINT FK_9D81DFE1458B4EB8 FOREIGN KEY (news_item_id) REFERENCES news_item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE market_analysis DROP FOREIGN KEY FK_ED82D2F8458B4EB8');
        $this->addSql('ALTER TABLE news_article_info DROP FOREIGN KEY FK_9D81DFE1458B4EB8');
        $this->addSql('DROP TABLE market_analysis');
        $this->addSql('DROP TABLE news_article_info');
    }
}
