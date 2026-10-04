<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_attributes')]
#[ORM\Index(name: 'idx_product_attributes_product_id', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_attributes_product_key', columns: ['product_id', 'key'])]
class ProductAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'string', length: 255)]
    private string $key;

    #[ORM\Column(type: 'text')]
    private string $value;

    public function __construct(Product $product, string $key, string $value)
    {
        $this->product = $product;
        $this->key = $key;
        $this->value = $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return array{key: string, value: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
        ];
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
