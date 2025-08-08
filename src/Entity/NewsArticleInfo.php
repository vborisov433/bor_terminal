<?php

namespace App\Entity;

use App\Repository\NewsArticleInfoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsArticleInfoRepository::class)]
class NewsArticleInfo
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: "articleInfo", targetEntity: NewsItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?NewsItem $newsItem = null;

    #[ORM\Column(type: 'boolean')]
    private bool $hasMarketImpact = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $titleHeadline = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $newsSurpriseIndex = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $economyImpact = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $macroKeywordHeatmap = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getNewsItem(): ?NewsItem
    {
        return $this->newsItem;
    }

    public function setNewsItem(?NewsItem $newsItem): void
    {
        $this->newsItem = $newsItem;
    }

    public function hasMarketImpact(): bool
    {
        return $this->hasMarketImpact;
    }

    public function setHasMarketImpact(bool $hasMarketImpact): void
    {
        $this->hasMarketImpact = $hasMarketImpact;
    }

    public function getTitleHeadline(): ?string
    {
        return $this->titleHeadline;
    }

    public function setTitleHeadline(?string $titleHeadline): void
    {
        $this->titleHeadline = $titleHeadline;
    }

    public function getNewsSurpriseIndex(): ?int
    {
        return $this->newsSurpriseIndex;
    }

    public function setNewsSurpriseIndex(?int $newsSurpriseIndex): void
    {
        $this->newsSurpriseIndex = $newsSurpriseIndex;
    }

    public function getEconomyImpact(): ?int
    {
        return $this->economyImpact;
    }

    public function setEconomyImpact(?int $economyImpact): void
    {
        $this->economyImpact = $economyImpact;
    }

    public function getMacroKeywordHeatmap(): ?array
    {
        return $this->macroKeywordHeatmap;
    }

    public function setMacroKeywordHeatmap(?array $macroKeywordHeatmap): void
    {
        $this->macroKeywordHeatmap = $macroKeywordHeatmap;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    public function toString(): string
    {
        return sprintf(
            'Headline: %s | Market Impact: %s | Surprise Index: %s | Economy Impact: %s',
            $this->getTitleHeadline() ?? 'N/A',
            $this->hasMarketImpact() ? 'Yes' : 'No',
            $this->getNewsSurpriseIndex() ?? 'N/A',
            $this->getEconomyImpact() ?? 'N/A'
        );
    }

}
