<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Crawler $crawler;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->crawler = $this->client->request('GET', '/');
    }

    public function testHomePage(): void
    {
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Playora');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('header.site-header');
        self::assertSelectorExists('main#accueil');
        self::assertSelectorExists('footer.site-footer');
    }

    public function testSkipLinkTargetsTheMainContent(): void
    {
        self::assertSame('#accueil', $this->crawler->filter('a.skip-link')->attr('href'));
    }

    public function testLogoIsTheHomeLink(): void
    {
        $logo = $this->crawler->filter('.site-header__brand');

        self::assertSame('/', $logo->attr('href'));
        self::assertSame('page', $logo->attr('aria-current'));
    }

    public function testNavigationsAreLabelled(): void
    {
        self::assertSelectorExists('nav.site-nav[aria-label="Menu principal"]');
        self::assertSelectorExists('nav.site-footer__nav[aria-label="Liens du pied de page"]');
        self::assertSelectorCount(4, '.site-nav__link');
    }

    public function testSectionsAreLabelledByTheirTitle(): void
    {
        $this->crawler->filter('main section')->each(function (Crawler $section): void {
            $titleId = $section->attr('aria-labelledby');

            self::assertNotEmpty($titleId);
            self::assertCount(1, $this->crawler->filter('#'.$titleId));
        });
    }

    public function testGamesAreListed(): void
    {
        self::assertSelectorCount(4, '.games__list .game');
        self::assertSelectorTextContains('.game--orange .game__name', 'Belote');
        self::assertSelectorCount(1, '.games__list a.button');
        self::assertSelectorCount(3, '.game__soon');
    }

    public function testStepsAreOrdered(): void
    {
        self::assertSelectorCount(3, 'ol.steps__list > li.step');
    }

    public function testImagesHaveAnAlternativeOrAreDecorative(): void
    {
        $this->crawler->filter('img')->each(static function (Crawler $image): void {
            self::assertNotNull($image->attr('alt'), 'Chaque image doit avoir un attribut alt.');

            if ('' === $image->attr('alt')) {
                self::assertNotNull($image->closest('[aria-hidden="true"]'), 'Une image décorative doit être dans un bloc aria-hidden.');
            }
        });
    }

    public function testImagesHaveDimensions(): void
    {
        $this->crawler->filter('img')->each(static function (Crawler $image): void {
            self::assertNotEmpty($image->attr('width'));
            self::assertNotEmpty($image->attr('height'));
        });
    }

    public function testDescriptionIsFilled(): void
    {
        self::assertNotEmpty($this->crawler->filter('meta[name="description"]')->attr('content'));
    }
}
