<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Vérifie que l'application joint bien la base MySQL de test.
 */
final class DatabaseConnectionTest extends KernelTestCase
{
    public function testConnectionToMysql(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        self::assertInstanceOf(MySQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testDatabaseUsesUtf8mb4(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame('utf8mb4', $connection->fetchOne('SELECT @@character_set_database'));
    }

    public function testTestDatabaseIsUsed(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame('playora_test', $connection->fetchOne('SELECT DATABASE()'));
    }
}
