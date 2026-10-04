<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase\Auth;

use App\Entity\User;
use App\Service\Auth\JwtService;
use App\UseCase\Auth\AuthenticateUseCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Проверка заголовка Authorization: валидный токен разбирается в claims,
 * всё остальное отбрасывается без исключения — решение принимает middleware.
 */
final class AuthenticateUseCaseTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-unit-tests-32b';

    public function testDecodesIssuedToken(): void
    {
        $useCase = new AuthenticateUseCase(new JwtService(self::SECRET, 'HS256', 3600));

        $token = (new JwtService(self::SECRET, 'HS256', 3600))->issue($this->user('admin@example.com'));

        $claims = $useCase->handle('Bearer ' . $token);

        $this->assertNotNull($claims);
        $this->assertSame('admin@example.com', $claims->email);
        $this->assertSame('admin@example.com', $claims->subject);
        $this->assertGreaterThan(time(), $claims->expiresAt);
    }

    #[DataProvider('rejectedHeaderProvider')]
    public function testRejectsUnusableHeaders(?string $header): void
    {
        $useCase = new AuthenticateUseCase(new JwtService(self::SECRET, 'HS256', 3600));

        $this->assertNull($useCase->handle($header));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function rejectedHeaderProvider(): iterable
    {
        yield 'заголовка нет' => [null];
        yield 'схема Basic' => ['Basic abc'];
        yield 'пустой Bearer' => ['Bearer '];
    }

    public function testRejectsTokenSignedWithAnotherSecret(): void
    {
        $useCase = new AuthenticateUseCase(new JwtService(self::SECRET, 'HS256', 3600));
        $foreign = new JwtService('another-secret-key-32-bytes-long', 'HS256', 3600);

        $token = $foreign->issue($this->user('admin@example.com'));

        $this->assertNull($useCase->handle('Bearer ' . $token));
    }

    public function testRejectsExpiredToken(): void
    {
        $useCase = new AuthenticateUseCase(new JwtService(self::SECRET, 'HS256', 3600));
        $expiring = new JwtService(self::SECRET, 'HS256', -10);

        $token = $expiring->issue($this->user('admin@example.com'));

        $this->assertNull($useCase->handle('Bearer ' . $token));
    }

    private function user(string $email): User
    {
        return new User($email, password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'Администратор');
    }
}
