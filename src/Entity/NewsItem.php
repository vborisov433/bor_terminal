<?php

namespace App\Entity;

use App\Repository\NewsItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsItemRepository::class)]
#[ORM\UniqueConstraint(columns: ['link'])]
class NewsItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type:'integer')]
    private ?int $id = null;

    #[ORM\Column(type:'string')]
    private string $title;

    #[ORM\Column(type:'string', unique:true)]
    private string $link;

    #[ORM\Column(type:'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $gptAnalysis = null;

    #[ORM\Column(type: 'boolean')]
    private bool $analyzed = false;

    #[ORM\Column(type: 'boolean')]
    private bool $completed = false;

    #[ORM\OneToOne(mappedBy: "newsItem", targetEntity: NewsArticleInfo::class, cascade: ["persist", "remove"])]
    private ?NewsArticleInfo $articleInfo = null;

    #[ORM\OneToMany(mappedBy: 'newsItem', targetEntity: MarketAnalysis::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $marketAnalyses;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->marketAnalyses = new ArrayCollection();
    }

    public function getGptAnalysis(): ?array
    {
        return $this->gptAnalysis;
    }

    public function setGptAnalysis(?array $gptAnalysis): void
    {
        $this->gptAnalysis = $gptAnalysis;
    }

    public function isAnalyzed(): bool
    {
        return $this->analyzed;
    }

    public function setAnalyzed(bool $analyzed): void
    {
        $this->analyzed = $analyzed;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setLink(string $link): void
    {
        $this->link = $link;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticleInfo(): ?NewsArticleInfo
    {
        return $this->articleInfo;
    }

    public function setArticleInfo(?NewsArticleInfo $articleInfo): void
    {
        $this->articleInfo = $articleInfo;
    }

    public function getMarketAnalyses(): Collection
    {
        return $this->marketAnalyses;
    }

    public function setMarketAnalyses(Collection $marketAnalyses): void
    {
        $this->marketAnalyses = $marketAnalyses;
    }

    public function addMarketAnalysis(MarketAnalysis $marketAnalysis): self
    {
        if (!$this->marketAnalyses->contains($marketAnalysis)) {
            $this->marketAnalyses[] = $marketAnalysis;
            $marketAnalysis->setNewsItem($this);
        }
        return $this;
    }

    public function removeMarketAnalysis(MarketAnalysis $marketAnalysis): self
    {
        if ($this->marketAnalyses->removeElement($marketAnalysis)) {
            // set the owning side to null (unless already changed)
            if ($marketAnalysis->getNewsItem() === $this) {
                $marketAnalysis->setNewsItem(null);
            }
        }
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): void
    {
        $this->completed = $completed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function marketAnalysesToString(): string
    {
        return implode(',', $this->marketAnalyses->map(fn(MarketAnalysis $ma) => $ma->toString())->toArray());
    }

}