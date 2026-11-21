<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Controller\ReviewController;
use FormBridge\DraftStore;
use FormBridge\Event\FormSubmitEvent;
use FormBridge\FormToolRunner;
use FormBridge\Mcp\FormToolResponder;
use FormBridge\Tests\App\Orders;
use FormBridge\Tests\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A draft can be reached through the form in the conversation and through the review page, and
 * saved once, whichever way is used.
 */
final class ReviewPageTest extends FunctionalTestCase
{
    public function testThePersonSavesOnTheReviewPage(): void
    {
        ['reviewUrl' => $reviewUrl] = $this->draft();

        $page = $this->client->request('GET', $reviewUrl);
        self::assertResponseIsSuccessful();
        self::assertSame('espresso', $page->filter('select[name="product"] option[selected]')->attr('value'));
        self::assertSame('Lyon', $page->filter('input[name="delivery[city]"]')->attr('value'));

        $this->client->submit($page->selectButton('Order')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fb-done', 'Order #1: 2 × espresso to Lyon.');
        self::assertCount(1, self::getContainer()->get(Orders::class)->all());
    }

    public function testTheLinkAloneIsNotEnoughToSave(): void
    {
        ['reviewUrl' => $reviewUrl] = $this->draft();

        $this->client->request('POST', $reviewUrl, ['product' => 'espresso', 'quantity' => '2', 'email' => 'mia@example.com', 'delivery' => ['city' => 'Lyon']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
    }

    public function testSavingOnThePageClosesTheFormInTheConversationToo(): void
    {
        ['reviewUrl' => $reviewUrl, 'submitToken' => $submitToken] = $this->draft();
        $this->client->submit($this->client->request('GET', $reviewUrl)->selectButton('Order')->form());

        self::assertNull(self::getContainer()->get(DraftStore::class)->findBySubmitToken($submitToken, 'place_order'));
        $this->client->request('GET', $reviewUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAFormThatIsNotValidStaysOpenForCorrection(): void
    {
        ['reviewUrl' => $reviewUrl] = $this->draft(['quantity' => 9] + self::ORDER);

        $page = $this->client->request('GET', $reviewUrl);
        self::assertSelectorTextContains('form ul', 'This value should be between 1 and 5.');

        $page = $this->client->submit($page->selectButton('Order')->form());
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($page->selectButton('Order')->form(['quantity' => '1']));
        self::assertSelectorTextContains('.fb-done', 'Order #1: 1 × espresso to Lyon.');
    }

    public function testAFormAListenerRefusedIsNotSavedAndStaysOpen(): void
    {
        ['reviewUrl' => $reviewUrl] = $this->draft();
        $this->client->disableReboot();
        self::getContainer()->get('event_dispatcher')->addListener(FormSubmitEvent::class, static function (FormSubmitEvent $event): void {
            $event->refuse('Sign in to order.');
        });

        $page = $this->client->request('GET', $reviewUrl);
        $this->client->submit($page->selectButton('Order')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form ul', 'Sign in to order.');
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
        $this->client->request('GET', $reviewUrl);
        self::assertResponseIsSuccessful();
    }

    public function testASaveThatWasInterruptedCannotBeRepeated(): void
    {
        ['reviewUrl' => $reviewUrl, 'submitToken' => $submitToken] = $this->draft();
        $container = self::getContainer();
        $drafts = $container->get(DraftStore::class);
        $runner = $container->get(FormToolRunner::class);
        $draft = $drafts->findBySubmitToken($submitToken, 'place_order');
        self::assertNotNull($draft);

        try {
            $drafts->claim($draft, static function () use ($runner): never {
                $runner->submit('place_order', self::ORDER);

                throw new \RuntimeException('The request failed after saving.');
            });
            self::fail('The interruption was swallowed.');
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $container->get(Orders::class)->all());
        self::assertNull($drafts->findBySubmitToken($submitToken, 'place_order'));
        $this->client->request('GET', $reviewUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCorrectingAFormDoesNotExtendItsLifetime(): void
    {
        ['submitToken' => $submitToken] = $this->draft(['quantity' => 9] + self::ORDER);
        $container = self::getContainer();
        $drafts = $container->get(DraftStore::class);
        $runner = $container->get(FormToolRunner::class);
        $draft = $drafts->findBySubmitToken($submitToken, 'place_order');
        self::assertNotNull($draft);

        $outcome = $drafts->claim($draft, static fn () => $runner->submit('place_order', ['quantity' => 9] + self::ORDER));

        self::assertFalse($outcome?->saved);
        self::assertEquals($draft->expiresAt, $drafts->findBySubmitToken($submitToken, 'place_order')?->expiresAt);
        self::assertLessThanOrEqual(3600, $draft->expiresAt->getTimestamp() - time());
    }

    public function testThePagesMayNotBeFramedByAnotherSite(): void
    {
        ['reviewUrl' => $reviewUrl] = $this->draft();

        $this->client->request('GET', $reviewUrl);

        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'self'");
        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
    }

    public function testWithTheReviewPageSwitchedOffThereIsNoLinkAndNoPage(): void
    {
        $container = self::getContainer();
        $runner = $container->get(FormToolRunner::class);
        $drafts = $container->get(DraftStore::class);

        $responder = new FormToolResponder($container->get('twig'), $drafts, $container->get('router'), reviewPage: false);
        $result = $responder->forDraft($runner->draft('place_order', self::ORDER), self::ORDER);
        self::assertArrayNotHasKey('reviewUrl', $result->structuredContent);
        self::assertStringNotContainsString('/_formbridge/review/', $result->content[0]->text);

        $controller = new ReviewController($drafts, $runner, $container->get('twig'), $container->get('security.csrf.token_manager'), enabled: false);
        $this->expectException(NotFoundHttpException::class);
        $controller(new Request(), $drafts->open('place_order', self::ORDER)->reviewToken);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{reviewUrl: string, submitToken: string}
     */
    private function draft(array $values = self::ORDER): array
    {
        $container = self::getContainer();
        $result = $container->get(FormToolResponder::class)->forDraft($container->get(FormToolRunner::class)->draft('place_order', $values), $values);

        return [
            'reviewUrl' => (string) parse_url($result->structuredContent['reviewUrl'], \PHP_URL_PATH),
            'submitToken' => (string) (new Crawler($result->meta['formbridge/html']))->filter('input[name="_formbridge_token"]')->attr('value'),
        ];
    }
}
