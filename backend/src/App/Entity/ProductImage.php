<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
#[ORM\Index(name: 'idx_product_images_product_id', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_images_product_url', columns: ['product_id', 'url'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'string', length: 2048)]
    private string $url;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $path;

    public function __construct(Product $product, string $url, ?string $path = null)
    {
        $this->product = $product;
        $this->url = $url;
        $this->path = $path;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * @return array{url: string, path: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'path' => $this->path,
        ];
    }

    public function setPath(?string $path): void
    {
        $this->path = $path;
    }
}
