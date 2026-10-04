<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\Index(name: 'idx_products_name', columns: ['name'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'external_code', type: 'string', length: 255, unique: true)]
    private string $externalCode;

    #[ORM\Column(type: 'string', length: 512)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $price;

    #[ORM\Column(name: 'purchase_price', type: 'decimal', precision: 15, scale: 2)]
    private string $purchasePrice;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $discount;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductAttribute> */
    #[ORM\OneToMany(
        targetEntity: ProductAttribute::class,
        mappedBy: 'product',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['key' => 'ASC'])]
    private Collection $attributes;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(
        targetEntity: ProductImage::class,
        mappedBy: 'product',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $images;

    public function __construct(
        string $externalCode,
        string $name,
        string $description = '',
        float $price = 0.0,
        float $purchasePrice = 0.0,
    ) {
        $this->externalCode = $externalCode;
        $this->name = $name;
        $this->description = $description;
        $this->price = self::formatAmount($price);
        $this->purchasePrice = self::formatAmount($purchasePrice);
        $this->discount = '0.00';
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->attributes = new ArrayCollection();
        $this->images = new ArrayCollection();

        $this->recalculateDiscount();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getExternalCode(): string
    {
        return $this->externalCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getPurchasePrice(): string
    {
        return $this->purchasePrice;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, ProductAttribute> */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    /**
     * Представление товара для списка: только поля, нужные в каталоге.
     *
     * @return array{id: int, external_code: string, name: string, price: string, discount: string, created_at: string, thumbnail_url: string|null}
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'external_code' => $this->externalCode,
            'name' => $this->name,
            'price' => $this->price,
            'discount' => $this->discount,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'thumbnail_url' => $this->thumbnailUrl(),
        ];
    }

    /**
     * Полное представление карточки товара с атрибутами и изображениями.
     *
     * @return array{
     *     id: int,
     *     external_code: string,
     *     name: string,
     *     description: string,
     *     price: string,
     *     purchase_price: string,
     *     discount: string,
     *     created_at: string,
     *     updated_at: string,
     *     attributes: list<array{key: string, value: string}>,
     *     images: list<array{url: string, path: string|null}>,
     * }
     */
    public function toCardArray(): array
    {
        return [
            'id' => $this->id,
            'external_code' => $this->externalCode,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'purchase_price' => $this->purchasePrice,
            'discount' => $this->discount,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'attributes' => array_values(array_map(
                static fn (ProductAttribute $attribute): array => $attribute->toArray(),
                $this->attributes->toArray(),
            )),
            'images' => array_values(array_map(
                static fn (ProductImage $image): array => $image->toArray(),
                $this->images->toArray(),
            )),
        ];
    }

    public function update(string $name, string $description, float $price, float $purchasePrice): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->price = self::formatAmount($price);
        $this->purchasePrice = self::formatAmount($purchasePrice);
        $this->updatedAt = new DateTimeImmutable();

        $this->recalculateDiscount();
    }

    /**
     * Обновляет набор атрибутов по ключу: существующие ключи меняют значение,
     * отсутствующие удаляются, новые добавляются. Полная очистка коллекции
     * ломала бы уникальный индекс (product_id, key) при повторном импорте.
     *
     * @param array<string, string> $attributes
     */
    public function replaceAttributes(array $attributes): void
    {
        $existing = [];

        foreach ($this->attributes as $attribute) {
            $existing[$attribute->getKey()] = $attribute;
        }

        foreach ($existing as $key => $attribute) {
            if (!array_key_exists($key, $attributes)) {
                $this->attributes->removeElement($attribute);
            }
        }

        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if (isset($existing[$key])) {
                $existing[$key]->setValue((string) $value);

                continue;
            }

            $this->attributes->add(new ProductAttribute($this, $key, (string) $value));
        }
    }

    /**
     * Обновляет изображения по URL: уже загруженный файл не перекачивается,
     * новые ссылки добавляются, исчезнувшие удаляются.
     *
     * @param list<array{url: string, path: string|null}> $images
     */
    public function replaceImages(array $images): void
    {
        $existing = [];

        foreach ($this->images as $image) {
            $existing[$image->getUrl()] = $image;
        }

        $actualUrls = [];

        foreach ($images as $image) {
            $actualUrls[] = $image['url'];
        }

        foreach ($existing as $url => $image) {
            if (!in_array($url, $actualUrls, true)) {
                $this->images->removeElement($image);
            }
        }

        foreach ($images as $image) {
            if (isset($existing[$image['url']])) {
                $existing[$image['url']]->setPath($image['path']);

                continue;
            }

            $this->images->add(new ProductImage($this, $image['url'], $image['path']));
        }
    }

    private function thumbnailUrl(): ?string
    {
        foreach ($this->images as $image) {
            return $image->getPath() ?? $image->getUrl();
        }

        return null;
    }

    private function recalculateDiscount(): void
    {
        $price = (float) $this->price;

        if ($price <= 0.0) {
            $this->discount = '0.00';

            return;
        }

        $discount = (($price - (float) $this->purchasePrice) / $price) * 100;

        $this->discount = self::formatAmount(max($discount, 0.0));
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
