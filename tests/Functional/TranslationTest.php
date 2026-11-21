<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\FormToolRunner;
use FormBridge\Mcp\FormToolResponder;
use FormBridge\Tests\FunctionalTestCase;

/**
 * The texts FormBridge shows to people follow the application's locale.
 */
final class TranslationTest extends FunctionalTestCase
{
    public function testTheFormIsShownInTheVisitorsLanguage(): void
    {
        $container = self::getContainer();
        $result = $container->get(FormToolResponder::class)->forDraft($container->get(FormToolRunner::class)->draft('place_order', self::ORDER), self::ORDER);
        $reviewUrl = (string) parse_url($result->structuredContent['reviewUrl'], \PHP_URL_PATH);

        $this->client->request('GET', $reviewUrl, server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertSelectorTextContains('.fb-head p', 'Für Sie ausgefüllt.');
        self::assertSelectorTextContains('.fb-head p', 'klicken Sie dann auf „Order“');

        $this->client->request('GET', $reviewUrl, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertSelectorTextContains('.fb-head p', 'Filled in for you.');
    }

    public function testThePasteAMessagePageAndItsValidationMessageAreTranslated(): void
    {
        $page = $this->client->request('GET', '/_formbridge/fill/place_order', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSelectorTextContains('h2', 'Place an order : remplir à partir d’un message');
        self::assertSelectorTextContains('label', 'Le message');

        $this->client->submit($page->selectButton('Remplir le formulaire')->form(['message[text]' => '']), [], ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSelectorTextContains('form ul', 'Collez d’abord le message.');
    }
}
