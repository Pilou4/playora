<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\GameSetting;
use App\Enum\CardBack;
use App\Enum\CardSet;
use App\Enum\Carpet;
use App\Enum\Game;
use App\Repository\GameSettingRepository;

/**
 * Réglages d'un jeu : tapis, cartes, durées.
 */
final class GameSettingAdminControllerTest extends AdminTestCase
{
    public function testSettingsPageShowsTheChoicesWithTheDefaults(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/belote/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('main#reglages');
        self::assertSelectorTextContains('.admin__link--active[aria-current="page"]', 'Réglages');
        self::assertSelectorCount(\count(Carpet::cases()), 'input.admin-choice__input--carpet');
        self::assertSelectorCount(\count(CardSet::cases()), 'input.admin-choice__input--cards');
        self::assertSelectorCount(\count(CardBack::cases()), 'input.admin-choice__input--back');
        self::assertSelectorExists('input[name="reglages[cardBack]"][value="moderne"]:checked');
        self::assertSelectorCount(\count(GameSetting::TURN_SECONDS_CHOICES), 'input.admin-segmented__input[name="reglages[turnSeconds]"]');
        self::assertSelectorCount(\count(GameSetting::BID_SECONDS_CHOICES), 'input.admin-segmented__input[name="reglages[bidSeconds]"]');
        self::assertSelectorExists('.admin-preview--main.admin-preview--lagon');
        self::assertSelectorExists('input[name="reglages[carpet]"][value="lagon"]:checked');
        self::assertSelectorExists('input[name="reglages[turnSeconds]"][value="15"]:checked');
        self::assertSelectorExists('input[name="reglages[bidSeconds]"][value="15"]:checked');
    }

    public function testEachGameHasItsOwnSettings(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/coinche/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin__nav', 'Coinche');
        self::assertSelectorTextContains('.admin__nav', 'Contrée');

        $this->client->submitForm('Enregistrer', [
            'reglages[carpet]' => 'nuit',
            'reglages[cardSet]' => 'moderne',
            'reglages[turnSeconds]' => '20',
            'reglages[bidSeconds]' => '30',
        ]);
        self::assertResponseRedirects('/admin/jeux/coinche/reglages', 303);

        $this->entityManager->clear();
        $settings = static::getContainer()->get(GameSettingRepository::class);
        self::assertSame(30, $settings->forGame(Game::Coinche)->getBidSeconds());
        self::assertNull($settings->forGame(Game::Belote)->getId());
    }

    public function testTarotOffersTarotCards(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/tarot/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="reglages[cardSet]"][value="tarot-nouveau"]:checked');
        self::assertSelectorExists('input[name="reglages[cardSet]"][value="tarot-moderne"]');
        self::assertSelectorNotExists('input[name="reglages[cardSet]"][value="moderne"]');

        // Le Tarot moderne se choisit comme 2e jeu de cartes
        $this->client->submitForm('Enregistrer', ['reglages[cardSet]' => 'tarot-moderne']);
        self::assertResponseRedirects('/admin/jeux/tarot/reglages', 303);

        $this->entityManager->clear();
        self::assertSame(CardSet::TarotModerne, static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Tarot)->getCardSet());
    }

    public function testRamiHasATurnTimeButNoBiddingTime(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/rami/reglages');

        self::assertResponseIsSuccessful();
        // 30 secondes par défaut : un tour de rami compte plusieurs gestes
        self::assertSelectorExists('input[name="reglages[turnSeconds]"][value="30"]:checked');
        self::assertSelectorNotExists('input[name="reglages[bidSeconds]"]');
        self::assertSelectorExists('a[href="/admin/jeux/rami/statistiques"]');

        $this->client->submitForm('Enregistrer', ['reglages[turnSeconds]' => '20']);
        self::assertResponseRedirects('/admin/jeux/rami/reglages', 303);

        $this->entityManager->clear();
        self::assertSame(20, static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Rami)->getTurnSeconds());
    }

    public function testSolitaireHasNoTurnDurations(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/solitaire/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="reglages[cardSet]"][value="moderne"]:checked');
        self::assertSelectorNotExists('input[name="reglages[turnSeconds]"]');
        self::assertSelectorNotExists('input[name="reglages[bidSeconds]"]');
        self::assertSelectorExists('a[href="/admin/jeux/solitaire/statistiques"]');

        $this->client->submitForm('Enregistrer', [
            'reglages[carpet]' => 'nuit',
            'reglages[cardSet]' => 'moderne',
        ]);
        self::assertResponseRedirects('/admin/jeux/solitaire/reglages', 303);

        $this->entityManager->clear();
        $setting = static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Solitaire);
        self::assertSame(Carpet::Night, $setting->getCarpet());
    }

    public function testUnknownCardBackIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $crawler = $this->client->request('GET', '/admin/jeux/belote/reglages');
        $token = $crawler->filter('input[name="reglages[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/jeux/belote/reglages', ['reglages' => [
            'carpet' => 'lagon',
            'cardSet' => 'moderne',
            'cardBack' => 'inconnu',
            'turnSeconds' => '15',
            'bidSeconds' => '15',
            '_token' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminSavesTheSettings(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/belote/reglages');

        $this->client->submitForm('Enregistrer', [
            'reglages[carpet]' => 'nuit',
            'reglages[cardSet]' => 'moderne',
            'reglages[turnSeconds]' => '20',
            'reglages[bidSeconds]' => '10',
        ]);

        self::assertResponseRedirects('/admin/jeux/belote/reglages', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-flash__message--success', 'Réglages enregistrés');
        self::assertSelectorExists('.admin-preview--main.admin-preview--nuit');

        $this->entityManager->clear();
        $setting = static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Belote);
        self::assertNotNull($setting->getId());
        self::assertSame(Carpet::Night, $setting->getCarpet());
        self::assertSame(20, $setting->getTurnSeconds());
        self::assertSame(10, $setting->getBidSeconds());
    }

    public function testSettingsAreSavedWithoutReloadingThePage(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $crawler = $this->client->request('GET', '/admin/jeux/belote/reglages');
        $token = $crawler->filter('input[name="reglages[_token]"]')->attr('value');
        $send = fn (string $turnSeconds) => $this->client->request('POST', '/admin/jeux/belote/reglages', ['reglages' => [
            'carpet' => 'vert',
            'cardSet' => 'moderne',
            'cardBack' => 'moderne',
            'turnSeconds' => $turnSeconds,
            'bidSeconds' => '20',
            '_token' => $token,
        ]], [], ['HTTP_ACCEPT' => 'application/json']);

        $send('30');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Réglages enregistrés', json_decode((string) $this->client->getResponse()->getContent(), true)['message']);
        self::assertSame(30, static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Belote)->getTurnSeconds());

        $send('99');
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Réglages non enregistrés', json_decode((string) $this->client->getResponse()->getContent(), true)['message']);
    }

    public function testUnknownDurationIsRefused(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $crawler = $this->client->request('GET', '/admin/jeux/belote/reglages');
        $token = $crawler->filter('input[name="reglages[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/jeux/belote/reglages', ['reglages' => [
            'carpet' => 'lagon',
            'cardSet' => 'moderne',
            'cardBack' => 'moderne',
            'turnSeconds' => '99',
            'bidSeconds' => '15',
            '_token' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Belote)->getId());
    }

    public function testNewRoomsUseTheDurationsAndTheTableUsesTheCarpet(): void
    {
        $setting = new GameSetting(Game::Belote);
        $setting->update(Carpet::Forest, CardSet::Modern, 30, 20);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        $user = $this->createUser('Fred');
        $game = $this->gameAgainstBots($user);

        self::assertSame(30, $game->getRoom()->getTurnSeconds());
        self::assertSame(20, $game->getRoom()->getBidSeconds());

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/belote/parties/'.$game->getRoom()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main#partie.belote--vert');
        self::assertSelectorNotExists('.carpets');
    }

    public function testGamesInProgressKeepTheirSettings(): void
    {
        $setting = new GameSetting(Game::Belote);
        $setting->update(Carpet::Forest, CardSet::Modern, 30, 20);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        $user = $this->createUser('Fred');
        $game = $this->gameAgainstBots($user);

        // L'admin change les réglages pendant la partie : elle garde ceux de sa création
        $setting->update(Carpet::Night, CardSet::Modern, 10, 10);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/jeux/belote/parties/'.$game->getRoom()->getId());

        self::assertSelectorExists('main#partie.belote--vert');
        self::assertSame(Carpet::Forest, $game->getRoom()->getCarpet());
        self::assertSame(30, $game->getRoom()->getTurnSeconds());
        self::assertSame(20, $game->getRoom()->getBidSeconds());
    }

    public function testAGameIsActiveByDefault(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('GET', '/admin/jeux/golf/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.admin-active[action="/admin/jeux/golf/actif"] input[name="active"][value="0"]');
        self::assertSelectorTextContains('.admin-active__chip', 'En ligne');
        self::assertSelectorTextContains('.admin-active__button', 'Mettre hors ligne');
        // Pastille « inactif » du menu : présente pour chaque jeu, cachée tant que le jeu est actif
        self::assertSelectorCount(\count(Game::cases()), '.admin__nav-badge');
        self::assertSelectorCount(\count(Game::cases()), '.admin__nav-badge--hidden');
        self::assertSelectorExists('.admin__nav-scroll .admin__link--active[aria-current="page"]');
    }

    public function testAdminSwitchesAGameOffAndOnWithoutReloading(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $token = $this->activeToken('golf');

        $this->client->request('POST', '/admin/jeux/golf/actif', ['_token' => $token, 'active' => '0'], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['active']);
        self::assertStringContainsString('hors ligne', $body['message']);

        $this->entityManager->clear();
        $settings = static::getContainer()->get(GameSettingRepository::class);
        self::assertFalse($settings->forGame(Game::Golf)->isActive());
        self::assertSame([Game::Golf], $settings->inactiveGames());

        // La page des réglages et le menu le montrent
        $this->client->request('GET', '/admin/jeux/golf/reglages');
        self::assertSelectorExists('form.admin-active--off input[name="active"][value="1"]');
        self::assertSelectorTextContains('.admin-active__chip', 'Hors ligne');
        self::assertSelectorTextContains('.admin-active__button', 'Mettre en ligne');
        self::assertSelectorCount(1, '.admin__nav-badge:not(.admin__nav-badge--hidden)');
        self::assertSelectorTextContains('.admin__nav-badge:not(.admin__nav-badge--hidden)', 'hors ligne');

        $this->client->request('POST', '/admin/jeux/golf/actif', ['_token' => $token, 'active' => '1'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['active']);
        $this->entityManager->clear();
        self::assertSame([], static::getContainer()->get(GameSettingRepository::class)->inactiveGames());
    }

    public function testSwitchingWithoutJavascriptComesBackToTheSettings(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $token = $this->activeToken('rami');

        $this->client->request('POST', '/admin/jeux/rami/actif', ['_token' => $token, 'active' => '0']);

        self::assertResponseRedirects('/admin/jeux/rami/reglages', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-flash__message--success', 'Rami est hors ligne');
    }

    public function testSwitchingNeedsAValidToken(): void
    {
        $this->client->loginUser($this->createUser('Fred', admin: true));
        $this->client->request('POST', '/admin/jeux/golf/actif', ['_token' => 'faux'], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertTrue(static::getContainer()->get(GameSettingRepository::class)->forGame(Game::Golf)->isActive());
    }

    public function testOnlyAdminsSwitchAGame(): void
    {
        $this->client->loginUser($this->createUser('Lea'));
        $this->client->request('POST', '/admin/jeux/golf/actif', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    private function activeToken(string $game): string
    {
        $crawler = $this->client->request('GET', '/admin/jeux/'.$game.'/reglages');

        return (string) $crawler->filter('form.admin-active input[name="_token"]')->attr('value');
    }
}
