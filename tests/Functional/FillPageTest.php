<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Tests\App\Orders;
use FormBridge\Tests\App\ScriptedModel;
use FormBridge\Tests\FunctionalTestCase;

/**
 * The "paste a message" page.
 */
final class FillPageTest extends FunctionalTestCase
{
    public function testAPastedMessageBecomesAFilledInFormToReview(): void
    {
        ScriptedModel::$answer = json_encode([
            'product' => 'filter', 'quantity' => 1, 'email' => 'sam@example.com', 'delivery' => ['city' => 'Porto', 'floor' => 3], 'coupon' => 'FREE',
        ], \JSON_THROW_ON_ERROR);

        $review = $this->paste('One bag of the filter roast to Porto please. Sam, sam@example.com');

        self::assertSame('place_order', ScriptedModel::$calls[0]['response_format']['json_schema']['name']);
        self::assertSelectorTextContains('h2', 'Place an order');
        self::assertSame('filter', $review->filter('select[name="product"] option[selected]')->attr('value'));
        self::assertSame('sam@example.com', $review->filter('input[name="email"]')->attr('value'));
        self::assertSame('Porto', $review->filter('input[name="delivery[city]"]')->attr('value'));
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
    }

    public function testAModelFailureIsAnsweredOnThePage(): void
    {
        ScriptedModel::$answer = new \RuntimeException('The provider is down.');

        $page = $this->client->request('GET', '/_formbridge/fill/place_order');
        $this->client->submit($page->selectButton('Fill in the form')->form(['message[text]' => 'One bag please.']));

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('form ul', 'The message could not be read right now.');
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $page = $this->client->request('GET', '/_formbridge/fill/place_order');
        $this->client->submit($page->selectButton('Fill in the form')->form(['message[text]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form ul', 'Paste the message first.');
        self::assertSame([], ScriptedModel::$calls);
    }

    public function testReadingMessagesIsRateLimitedPerClient(): void
    {
        // The limiter counts in memory, so the kernel must stay up between requests.
        $this->client->disableReboot();

        for ($i = 0; $i < 2; ++$i) {
            $page = $this->client->request('GET', '/_formbridge/fill/place_order');
            $this->client->submit($page->selectButton('Fill in the form')->form(['message[text]' => 'One bag please.']));
            self::assertResponseRedirects();
        }

        $page = $this->client->request('GET', '/_formbridge/fill/place_order');
        $this->client->submit($page->selectButton('Fill in the form')->form(['message[text]' => 'One bag please.']));

        self::assertResponseStatusCodeSame(429);
        self::assertCount(2, ScriptedModel::$calls);
    }

    public function testThereIsNoSuchPageForAnUnknownForm(): void
    {
        $this->client->request('GET', '/_formbridge/fill/delete_everything');

        self::assertResponseStatusCodeSame(404);
    }

    private function paste(string $message): \Symfony\Component\DomCrawler\Crawler
    {
        $page = $this->client->request('GET', '/_formbridge/fill/place_order');
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Fill in the form')->form(['message[text]' => $message]));
        self::assertResponseRedirects();

        return $this->client->followRedirect();
    }
}
