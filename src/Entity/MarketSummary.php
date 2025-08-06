<?php

namespace App\Entity;

use App\Repository\MarketSummaryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketSummaryRepository::class)]
class MarketSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "text")]
    private string $htmlResult;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getHtmlResult(): string
    {
        return $this->htmlResult;
    }

    public function setHtmlResult(string $htmlResult): void
    {
        $this->htmlResult = $htmlResult;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

}