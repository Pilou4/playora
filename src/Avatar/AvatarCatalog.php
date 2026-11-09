<?php

declare(strict_types=1);

namespace App\Avatar;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les pièces des avatars (assets/avatars/pieces.json), décrites une seule fois et lues
 * par le serveur (image) comme par le navigateur (éditeur).
 *
 * Un avatar est un code court : le style puis le numéro de l'option choisie dans chaque
 * emplacement, par exemple « portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1 ».
 */
final class AvatarCatalog
{
    /** Pour les adresses : uniquement des lettres, des points et des chiffres. */
    public const string CODE_PATTERN = '[a-z]+(?:\.\d{1,2}){1,24}';

    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/avatars/pieces.json')]
        private readonly string $path,
    ) {
    }

    public function json(): string
    {
        return (string) file_get_contents($this->path);
    }

    /**
     * Le style et les options choisies, ou null si le code ne correspond à aucun avatar possible.
     *
     * @return array{style: array<string, mixed>, options: array<string, array<string, mixed>>}|null
     */
    public function resolve(string $code): ?array
    {
        if (!preg_match('/^'.self::CODE_PATTERN.'$/D', $code)) {
            return null;
        }

        [$key, $choices] = explode('.', $code, 2);
        $style = $this->style($key);
        $choices = array_map('intval', explode('.', $choices));

        if (null === $style || \count($choices) !== \count($style['slots'])) {
            return null;
        }

        $options = [];
        foreach ($style['slots'] as $index => $slot) {
            $option = $slot['options'][$choices[$index]] ?? null;

            if (null === $option) {
                return null;
            }

            $options[$slot['key']] = $option;
        }

        return ['style' => $style, 'options' => $options];
    }

    public function isValid(string $code): bool
    {
        return null !== $this->resolve($code);
    }

    /**
     * Numéro de version des dessins : il fait partie de l'adresse des images, pour que les navigateurs
     * (qui gardent les images un an) prennent les nouveaux dessins dès qu'ils changent.
     */
    public function version(): int
    {
        return (int) $this->catalog()['version'];
    }

    /**
     * Avatars tout faits, proposés à l'inscription.
     *
     * @return list<string>
     */
    public function gallery(): array
    {
        return $this->catalog()['gallery'];
    }

    /**
     * Avatars des robots (une place de la table = un robot).
     *
     * @return list<string>
     */
    public function robots(): array
    {
        return $this->catalog()['robots'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function style(string $key): ?array
    {
        foreach ($this->catalog()['styles'] as $style) {
            if ($style['key'] === $key) {
                return $style;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        return $this->catalog ??= json_decode($this->json(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
