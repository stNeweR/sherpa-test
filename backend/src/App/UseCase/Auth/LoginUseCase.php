<?php

declare(strict_types=1);

namespace App\UseCase\Auth;

use App\Exception\InvalidInputException;
use App\Exception\UnauthenticatedException;
use App\Repository\UserRepository;
use App\Service\Auth\JwtService;

/**
 * Выдача JWT администратору. Вся логика входа — проверка полей, поиск
 * пользователя, сверка пароля и выпуск токена — живёт в handle().
 */
final readonly class LoginUseCase
{
    public function __construct(
        private UserRepository $users,
        private JwtService $jwt,
    ) {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int} */
    public function handle(string $email, string $password): array
    {
        if ($email === '' || $password === '') {
            throw new InvalidInputException('Укажите email и пароль');
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->verifyPassword($password)) {
            throw new UnauthenticatedException('Неверный email или пароль');
        }

        return [
            'access_token' => $this->jwt->issue($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttlSeconds(),
        ];
    }
}
