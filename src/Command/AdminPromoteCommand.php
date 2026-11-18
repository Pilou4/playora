<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Donne l'accès à l'administration à un compte : php bin/console app:admin:promote adresse@exemple.fr
 */
#[AsCommand(name: 'app:admin:promote', description: 'Donne le rôle administrateur à un compte.')]
final readonly class AdminPromoteCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Adresse e-mail du compte')] string $email): int
    {
        $user = $this->users->findOneBy(['email' => User::normalizeEmail($email)]);

        if (!$user instanceof User) {
            $io->error('Aucun compte avec cette adresse.');

            return Command::FAILURE;
        }

        $user->grantRole('ROLE_ADMIN');
        $this->entityManager->flush();
        $io->success(\sprintf('%s est maintenant administrateur. Il doit se reconnecter pour en profiter.', $user->getPseudo()));

        return Command::SUCCESS;
    }
}
