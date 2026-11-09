<?php

declare(strict_types=1);

namespace App\Avatar;

/**
 * Assemble l'image SVG d'un avatar. Même règle que assets/js/avatar/AvatarRenderer.js :
 * calques dans l'ordre, pièce choisie dans chaque emplacement, puis couleurs ({nom}) remplacées.
 * Rien ne vient du joueur : seuls des numéros d'options vérifiés sont utilisés.
 */
final readonly class AvatarRenderer
{
    public function __construct(
        private AvatarCatalog $catalog,
    ) {
    }

    /**
     * @param bool $face true : seulement le visage, en carré (rond du menu, des salons et de la table)
     */
    public function render(string $code, bool $face = false): ?string
    {
        $resolved = $this->catalog->resolve($code);

        if (null === $resolved) {
            return null;
        }

        ['style' => $style, 'options' => $options] = $resolved;
        $tokens = array_merge(...array_map(static fn (array $option): array => $option['tokens'] ?? [], array_values($options)));
        $body = '';

        foreach ($style['layers'] as $layer) {
            $body .= $layer['draw'] ?? $options[$layer['slot']]['parts'][$layer['part'] ?? 'main'] ?? '';
        }

        $viewBox = $face ? $style['face'] : $style['viewBox'];
        [, , $width, $height] = array_map('intval', explode(' ', $viewBox));
        $svg = \sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" width="%d" height="%d"><defs>%s</defs>%s</svg>', $viewBox, $width, $height, $style['defs'], $body);

        // Deux passes : une couleur peut renvoyer à une autre
        for ($pass = 0; $pass < 2; ++$pass) {
            $svg = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $match): string => $tokens[$match[1]] ?? '', $svg);
        }

        return $svg;
    }
}
