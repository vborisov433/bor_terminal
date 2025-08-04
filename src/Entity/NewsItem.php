<?php

namespace App\Entity;

use App\Repository\NewsItemRepository;
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

}