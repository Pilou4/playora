<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEmailIsNormalized(): void
    {
        $user = new User('  Fred@Exemple.FR ', ' Fred ');

        self::assertSame('fred@exemple.fr', $user->getEmail());
        self::assertSame('fred@exemple.fr', $user->getUserIdentifier());
        self::assertSame('Fred', $user->getPseudo());
    }

    public function testEveryUserHasRoleUser(): void
    {
        $user = new User('fred@exemple.fr', 'Fred');
        self::assertSame([User::ROLE_USER], $user->getRoles());

        $user->grantRole(User::ROLE_ADMIN);
        $user->grantRole(User::ROLE_ADMIN);
        $user->grantRole(User::ROLE_USER);

        self::assertEqualsCanonicalizing([User::ROLE_USER, User::ROLE_ADMIN], $user->getRoles());
    }

    public function testNewAccountIsActiveAndNotVerified(): void
    {
        $user = new User('fred@exemple.fr', 'Fred');

        self::assertFalse($user->isDeleted());
        self::assertFalse($user->isEmailVerified());
        self::assertEquals($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testVerificationDateIsKept(): void
    {
        $user = new User('fred@exemple.fr', 'Fred');
        $user->markEmailAsVerified();
        $first = $user->getEmailVerifiedAt();
        $user->markEmailAsVerified();

        self::assertTrue($user->isEmailVerified());
        self::assertSame($first, $user->getEmailVerifiedAt());
    }

    public function testDeletion(): void
    {
        $user = new User('fred@exemple.fr', 'Fred');
        $user->markAsDeleted();

        self::assertTrue($user->isDeleted());
        self::assertNotNull($user->getDeletedAt());
    }
}
