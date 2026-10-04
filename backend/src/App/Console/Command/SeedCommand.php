<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Entity\Product;
use App\Entity\User;
use App\Repository\UserRepository;
use Core\Bootstrap\Env;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed', description: 'Наполнить БД демонстрационными товарами, атрибутами и изображениями')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'fresh',
            'f',
            InputOption::VALUE_NONE,
            'Удалить существующие товары перед заполнением',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->seedAdmin();

        if ($input->getOption('fresh')) {
            $this->entityManager->createQuery('DELETE FROM App\Entity\Product p')->execute();
            $this->entityManager->clear();
            $output->writeln('<info>Существующие товары удалены.</info>');
        }

        $created = 0;

        foreach ($this->products() as $draft) {
            if ($this->exists($draft['external_code'])) {
                continue;
            }

            $product = new Product(
                externalCode: $draft['external_code'],
                name: $draft['name'],
                description: $draft['description'],
                price: $draft['price'],
                purchasePrice: $draft['purchase_price'],
            );
            $product->replaceAttributes($draft['attributes']);
            $product->replaceImages($draft['images']);

            $this->entityManager->persist($product);
            $created++;
        }

        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $output->writeln(sprintf('<info>Создано товаров: %d</info>', $created));
        $output->writeln(sprintf(
            '<info>Всего товаров: %d, атрибутов: %d, изображений: %d</info>',
            self::count($connection, 'SELECT COUNT(*) FROM products'),
            self::count($connection, 'SELECT COUNT(*) FROM product_attributes'),
            self::count($connection, 'SELECT COUNT(*) FROM product_images'),
        ));

        return Command::SUCCESS;
    }

    private function seedAdmin(): void
    {
        $email = Env::get('SEED_ADMIN_EMAIL', 'admin@example.com');
        $password = Env::get('SEED_ADMIN_PASSWORD', 'admin_secret');

        if ($this->users->findByEmail($email) !== null) {
            return;
        }

        $this->entityManager->persist(new User(
            email: $email,
            passwordHash: password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            displayName: 'Администратор',
        ));
        $this->entityManager->flush();

        fwrite(STDOUT, sprintf("<info>Создан администратор %s (пароль из SEED_ADMIN_PASSWORD)</info>\n", $email));
    }

    /** @param list<scalar> $params */
    private static function count(Connection $connection, string $sql, array $params = []): int
    {
        $value = $connection->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function exists(string $externalCode): bool
    {
        return self::count(
            $this->entityManager->getConnection(),
            'SELECT COUNT(*) FROM products WHERE external_code = ?',
            [$externalCode],
        ) > 0;
    }

    /**
     * @return list<array{
     *     external_code: string,
     *     name: string,
     *     description: string,
     *     price: float,
     *     purchase_price: float,
     *     attributes: array<string, string>,
     *     images: list<array{url: string, path: string|null}>
     * }>
     */
    private function products(): array
    {
        return [
            [
                'external_code' => 'SKU-BERM-001',
                'name' => 'Бермуды мужские, Grigio/Verde, OMSA, 46(M)',
                'description' => 'Бермуды из коллекции OMSA, сезон весна-лето.',
                'price' => 1320.0,
                'purchase_price' => 880.0,
                'attributes' => [
                    'Размер' => '46(M)',
                    'Цвет' => 'Grigio/Verde',
                    'Бренд' => 'OMSA',
                    'Состав' => '98% хлопок, 2% эластан',
                ],
                'images' => [['url' => 'https://cdn.sherpa.test/bermudy-1.jpg', 'path' => null]],
            ],
            [
                'external_code' => 'SKU-SHORT-002',
                'name' => 'Шорты детские, синий, L',
                'description' => 'Хлопковые шорты для мальчика.',
                'price' => 890.0,
                'purchase_price' => 445.0,
                'attributes' => [
                    'Размер' => 'L',
                    'Цвет' => 'Синий',
                    'Бренд' => "O'Kids",
                    'Сезон' => 'Лето',
                ],
                'images' => [
                    ['url' => 'https://cdn.sherpa.test/shorts-1.jpg', 'path' => null],
                    ['url' => 'https://cdn.sherpa.test/shorts-2.jpg', 'path' => null],
                ],
            ],
            [
                'external_code' => 'SKU-DRES-003',
                'name' => 'Платье женское, чёрное, M',
                'description' => 'Повседневное платье свободного кроя.',
                'price' => 2450.0,
                'purchase_price' => 1960.0,
                'attributes' => [
                    'Размер' => 'M',
                    'Цвет' => 'Чёрный',
                    'Бренд' => "O'Woman",
                ],
                'images' => [['url' => 'https://cdn.sherpa.test/dress-1.jpg', 'path' => null]],
            ],
            [
                'external_code' => 'SKU-SHOE-004',
                'name' => 'Кроссовки белые, 42',
                'description' => 'Лёгкие повседневные кроссовки.',
                'price' => 3490.0,
                'purchase_price' => 3141.0,
                'attributes' => [
                    'Размер' => '42',
                    'Цвет' => 'Белый',
                    'Подошва' => 'Резина',
                ],
                'images' => [],
            ],
            [
                'external_code' => 'SKU-JACK-005',
                'name' => 'Куртка джинсовая, S',
                'description' => 'Джинсовая куртка оверсайз.',
                'price' => 4290.0,
                'purchase_price' => 3861.0,
                'attributes' => [
                    'Размер' => 'S',
                    'Материал' => 'Деним',
                    'Цвет' => 'Синий',
                ],
                'images' => [['url' => 'https://cdn.sherpa.test/jacket-1.jpg', 'path' => null]],
            ],
            [
                'external_code' => 'SKU-SOCK-006',
                'name' => 'Носки детские, 2 пары, 28 см',
                'description' => 'Хлопковые носки с добавлением эластана.',
                'price' => 390.0,
                'purchase_price' => 273.0,
                'attributes' => [
                    'Размер' => '28 см',
                    'Количество' => '2 пары',
                ],
                'images' => [],
            ],
            [
                'external_code' => 'SKU-HAT-007',
                'name' => 'Кепка бейсболка, чёрная',
                'description' => 'Классическая кепка из хлопка.',
                'price' => 690.0,
                'purchase_price' => 552.0,
                'attributes' => [
                    'Цвет' => 'Чёрный',
                    'Тип' => 'Бейсболка',
                ],
                'images' => [],
            ],
            [
                'external_code' => 'SKU-GIFT-008',
                'name' => 'Подарочная упаковка',
                'description' => 'Фирменная коробка с лентой.',
                'price' => 150.0,
                'purchase_price' => 0.0,
                'attributes' => ['Бренд' => 'Sherpa'],
                'images' => [],
            ],
        ];
    }
}
