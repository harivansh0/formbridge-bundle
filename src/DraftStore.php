<?php

declare(strict_types=1);

namespace FormBridge;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Keeps filled-in forms until a person submits them, and lets each one be saved at most once.
 */
final class DraftStore
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly int $lifetime = 3600,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function open(string $toolName, array $values): Draft
    {
        $draft = new Draft(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            $toolName,
            $values,
            new \DateTimeImmutable('+'.$this->lifetime.' seconds'),
        );
        $this->keep($draft);

        return $draft;
    }

    public function findByReviewToken(string $token): ?Draft
    {
        if (!self::looksLikeAToken($token)) {
            return null;
        }
        $item = $this->cache->getItem($this->key('draft.'.$token));
        if (!$item->isHit()) {
            return null;
        }
        $stored = $item->get();
        if (!\is_array($stored) || !($stored['expires'] ?? null) instanceof \DateTimeImmutable) {
            return null;
        }

        return new Draft($token, $stored['submit'], $stored['tool'], $stored['values'], $stored['expires']);
    }

    public function findBySubmitToken(mixed $token, string $toolName): ?Draft
    {
        if (!self::looksLikeAToken($token)) {
            return null;
        }
        $item = $this->cache->getItem($this->key('submit.'.$token));
        $draft = $item->isHit() ? $this->findByReviewToken($item->get()) : null;

        return null !== $draft && $draft->tool === $toolName && hash_equals($draft->submitToken, $token) ? $draft : null;
    }

    /**
     * Runs a save attempt for a draft, one at a time per draft.
     *
     * Returns null without running anything when the draft is no longer open. The draft is closed
     * before the attempt runs and reopened, with its original expiry, only if the outcome says
     * nothing was saved; an attempt that dies after the handler saved cannot be repeated.
     *
     * @param callable(): FormOutcome $attempt
     */
    public function claim(Draft $draft, callable $attempt): ?FormOutcome
    {
        $lock = $this->lockFactory->createLock('formbridge-draft-'.$draft->reviewToken);
        $lock->acquire(true);
        try {
            if (null === $this->findByReviewToken($draft->reviewToken)) {
                return null;
            }
            if (!$this->cache->deleteItems([$this->key('draft.'.$draft->reviewToken), $this->key('submit.'.$draft->submitToken)])) {
                return null;
            }

            $outcome = $attempt();
            if (!$outcome->saved) {
                $this->keep($draft);
            }

            return $outcome;
        } finally {
            $lock->release();
        }
    }

    private function keep(Draft $draft): void
    {
        $stored = ['tool' => $draft->tool, 'values' => $draft->values, 'submit' => $draft->submitToken, 'expires' => $draft->expiresAt];
        $this->store('draft.'.$draft->reviewToken, $stored, $draft->expiresAt);
        $this->store('submit.'.$draft->submitToken, $draft->reviewToken, $draft->expiresAt);
    }

    private function store(string $name, mixed $value, \DateTimeImmutable $expiresAt): void
    {
        $item = $this->cache->getItem($this->key($name));
        $item->set($value);
        $item->expiresAt($expiresAt);

        if (!$this->cache->save($item)) {
            throw new \RuntimeException('The filled-in form could not be stored.');
        }
    }

    private function key(string $name): string
    {
        return 'formbridge.'.$name;
    }

    private static function looksLikeAToken(mixed $token): bool
    {
        return \is_string($token) && 1 === preg_match('/^[a-f0-9]{32}$/', $token);
    }
}
