<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * Jeton de renouvellement (paquet gesdinet/jwt-refresh-token-bundle).
 * Il permet d'obtenir un nouveau jeton d'accès sans redemander le mot de passe,
 * et on peut le supprimer pour couper l'accès (déconnexion, bannissement).
 * Les colonnes sont définies par le paquet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken extends BaseRefreshToken
{
}
