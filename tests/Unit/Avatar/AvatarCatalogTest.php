<?php

declare(strict_types=1);

namespace App\Tests\Unit\Avatar;

use App\Avatar\AvatarCatalog;
use App\Avatar\AvatarRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvatarCatalogTest extends TestCase
{
    private AvatarCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new AvatarCatalog(\dirname(__DIR__, 3).'/assets/avatars/pieces.json');
    }

    public function testEveryGalleryAvatarIsValidAndFullyDrawn(): void
    {
        $renderer = new AvatarRenderer($this->catalog);

        self::assertNotEmpty($this->catalog->gallery());

        foreach ([...$this->catalog->gallery(), ...$this->catalog->robots()] as $code) {
            $svg = $renderer->render($code);

            self::assertNotNull($svg, $code);
            self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
            // Toutes les couleurs ont été remplacées
            self::assertDoesNotMatchRegularExpression('/\{\w+\}/', $svg, $code);
            self::assertStringNotContainsString('<script', $svg);
        }
    }

    public function testFaceIsASquareCrop(): void
    {
        $renderer = new AvatarRenderer($this->catalog);
        $code = $this->catalog->gallery()[0];

        self::assertStringContainsString('width="240" height="300"', (string) $renderer->render($code));
        self::assertStringContainsString('width="180" height="180"', (string) $renderer->render($code, face: true));
    }

    public function testEveryPieceCanBeChosen(): void
    {
        $renderer = new AvatarRenderer($this->catalog);
        $base = explode('.', $this->catalog->gallery()[0]);
        $slots = json_decode($this->catalog->json(), true)['styles'][0]['slots'];

        foreach ($slots as $index => $slot) {
            foreach (array_keys($slot['options']) as $option) {
                $code = $base;
                $code[$index + 1] = (string) $option;

                self::assertDoesNotMatchRegularExpression('/\\{\\w+\\}/', (string) $renderer->render(implode('.', $code)), $slot['key'].' '.$option);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'style inconnu' => ['robot.0.0.0'];
        yield 'ancien avatar' => ['personnage.0.0.0.1.0.1.0.0.2.0'];
        yield 'trop peu de choix' => ['portrait.0.0'];
        yield 'option inexistante' => ['portrait.0.99.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1'];
        yield 'caractères interdits' => ['portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1<'];
        yield 'vide' => [''];
        yield 'nombre négatif' => ['portrait.0.-1.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1'];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesAreRefused(string $code): void
    {
        self::assertFalse($this->catalog->isValid($code));
        self::assertNull((new AvatarRenderer($this->catalog))->render($code));
    }
}
