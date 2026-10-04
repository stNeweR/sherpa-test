<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase\Auth;

use App\Entity\User;
use App\Exception\InvalidInputException;
use App\Exception\UnauthenticatedException;
use App\Repository\UserRepository;
use App\Service\Auth\JwtService;
use App\UseCase\Auth\LoginUseCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Use case входа владеет всей логикой: проверкой полей, поиском пользователя,
 * сверкой пароля и выпуском токена.
 */
final class LoginUseCaseTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-unit-tests-32b';

    public function testReturnsTokenForValidCredentials(): void
    {
        $useCase = $this->useCase($this->user('admin@example.com', 'secret'));

        $result = $useCase->handle('admin@example.com', 'secret');

        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame(3600, $result['expires_in']);
        $this->assertNotEmpty($result['access_token']);
    }

    public function testEmailIsCaseAndSpaceInsensitive(): void
    {
        $useCase = $this->useCase($this->user('admin@example.com', 'secret'));

        $result = $useCase->handle('  ADMIN@Example.com ', 'secret');

        $this->assertNotEmpty($result['access_token']);
    }

    public function testWrongPasswordIsUnauthorized(): void
    {
        $useCase = $this->useCase($this->user('admin@example.com', 'secret'));

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Неверный email или пароль');

        $useCase->handle('admin@example.com', 'wrong');
    }

    public function testUnknownUserIsUnauthorized(): void
    {
        $useCase = $this->useCase(null);

        $this->expectException(UnauthenticatedException::class);

        $useCase->handle('nobody@example.com', 'secret');
    }

    #[DataProvider('emptyCredentialsProvider')]
    public function testEmptyCredentialsAreRejectedBeforeUserLookup(string $email, string $password): void
    {
        $useCase = $this->useCase(null);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Укажите email и пароль');

        $useCase->handle($email, $password);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyCredentialsProvider(): iterable
    {
        yield 'оба пустые' => ['', ''];
        yield 'нет email' => ['', 'secret'];
        yield 'нет пароля' => ['admin@example.com', ''];
    }

    public function testErrorsCarryHttpStatus(): void
    {
        $useCase = $this->useCase(null);

        try {
            $useCase->handle('admin@example.com', 'wrong');
            $this->fail('Ожидалось исключение');
        } catch (UnauthenticatedException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame(['error' => 'Неверный email или пароль'], $e->errorBody());
        }

        try {
            $useCase->handle('', '');
            $this->fail('Ожидалось исключение');
        } catch (InvalidInputException $e) {
            $this->assertSame(400, $e->httpStatus());
        }
    }

    private function user(string $email, string $password): User
    {
        return new User($email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    private function useCase(?User $user): LoginUseCase
    {
        $repository = new class ($user) extends UserRepository {
            public function __construct(private readonly ?User $user)
            {
            }

            public function findByEmail(string $email): ?User
            {
                return $this->user !== null && $this->user->getEmail() === mb_strtolower(trim($email))
                    ? $this->user
                    : null;
            }
        };

        return new LoginUseCase($repository, new JwtService(self::SECRET, 'HS256', 3600));
    }
}
