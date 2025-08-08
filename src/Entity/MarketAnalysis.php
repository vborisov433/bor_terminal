<?php

namespace App\Entity;

use App\Repository\MarketAnalysisRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketAnalysisRepository::class)]
class MarketAnalysis
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NewsItem::class, inversedBy: "marketAnalyses")]
    #[ORM\JoinColumn(nullable: false)]
    private ?NewsItem $newsItem = null;

    #[ORM\Column(type: "string")]
    private string $market;

    #[ORM\Column(type: "string")]
    private string $sentiment;

    #[ORM\Column(type: "integer")]
    private int $magnitude;

    #[ORM\Column(type: "text")]
    private string $reason;

    #[ORM\Column(type: "json")]
    private array $keywords = [];

    #[ORM\Column(type: "json")]
    private array $categories = [];

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

    public function getMarket(): string
    {
        return $this->market;
    }

    public function setMarket(string $market): void
    {
        $this->market = $market;
    }

    public function getSentiment(): string
    {
        return $this->sentiment;
    }

    public function setSentiment(string $sentiment): void
    {
        $this->sentiment = $sentiment;
    }

    public function getMagnitude(): int
    {
        return $this->magnitude;
    }

    public function setMagnitude(int $magnitude): void
    {
        $this->magnitude = $magnitude;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }

    public function getKeywords(): array
    {
        return $this->keywords;
    }

    public function setKeywords(array $keywords): void
    {
        $this->keywords = $keywords;
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function setCategories(array $categories): void
    {
        $this->categories = $categories;
    }

    public function toString(): string
    {
        return sprintf('%s %s', $this->market, $this->sentiment);
    }

}
