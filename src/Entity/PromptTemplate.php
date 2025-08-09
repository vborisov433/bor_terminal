<?php

namespace App\Entity;

use App\Repository\PromptTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromptTemplateRepository::class)]
class PromptTemplate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type:'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $template;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): void
    {
        $this->template = $template;
    }
}
