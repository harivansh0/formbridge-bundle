<?php

declare(strict_types=1);

namespace FormBridge\Tests\Unit;

use FormBridge\DraftStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class DraftStoreTest extends TestCase
{
    public function testADraftExpiresAFixedNumberOfSecondsAfterItWasOpened(): void
    {
        $before = time();
        $draft = (new DraftStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), 120))->open('place_order', []);

        self::assertGreaterThanOrEqual($before + 120, $draft->expiresAt->getTimestamp());
        self::assertLessThanOrEqual(time() + 120, $draft->expiresAt->getTimestamp());
        self::assertSame(0, $draft->expiresAt->getOffset());
    }

    public function testADraftThatCouldNotBeStoredIsAnErrorNotAnUnusableLink(): void
    {
        $refusing = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);

        (new DraftStore($refusing, new LockFactory(new InMemoryStore())))->open('place_order', []);
    }

    public function testAChainedPoolIsRefusedBecauseItsLocalTierCanAnswerFromBeforeASave(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('chained cache pool');

        new DraftStore(new ChainAdapter([new ArrayAdapter(), new ArrayAdapter()]), new LockFactory(new InMemoryStore()));
    }

    public function testADraftIsFoundByEitherTokenOfItsOwnToolOnly(): void
    {
        $drafts = new DraftStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $draft = $drafts->open('place_order', ['quantity' => 2]);

        self::assertSame(['quantity' => 2], $drafts->findByReviewToken($draft->reviewToken)?->values);
        self::assertNotNull($drafts->findBySubmitToken($draft->submitToken, 'place_order'));
        self::assertNull($drafts->findBySubmitToken($draft->submitToken, 'another_tool'));
        self::assertNull($drafts->findBySubmitToken($draft->reviewToken, 'place_order'));
        self::assertNull($drafts->findByReviewToken('not-a-token'));
    }
}
