<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
final class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'display_name', type: 'string', length: 120)]
    private string $displayName;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $email, string $passwordHash, string $displayName = '')
    {
        $this->email = mb_strtolower(trim($email));
        $this->passwordHash = $passwordHash;
        $this->displayName = $displayName !== '' ? $displayName : $this->email;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }
}
