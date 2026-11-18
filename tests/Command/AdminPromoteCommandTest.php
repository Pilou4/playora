<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminPromoteCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester((new Application($kernel))->find('app:admin:promote'));
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAccountBecomesAdmin(): void
    {
        $user = new User('fred@exemple.fr', 'Fred');
        $user->setPassword('pas-utilise-dans-ces-tests');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // L'adresse est retrouvée quelle que soit la casse
        $this->tester->execute(['email' => ' Fred@Exemple.fr ']);

        $this->tester->assertCommandIsSuccessful();
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'fred@exemple.fr']);
        self::assertContains('ROLE_ADMIN', $saved?->getRoles() ?? []);
    }

    public function testUnknownAccountFails(): void
    {
        $this->tester->execute(['email' => 'personne@exemple.fr']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aucun compte', $this->tester->getDisplay());
    }

    private function purge(): void
    {
        $connection = $this->entityManager->getConnection();

        foreach (['reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
