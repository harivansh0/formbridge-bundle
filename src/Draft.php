<?php

declare(strict_types=1);

namespace FormBridge;

/**
 * A form an agent filled in, waiting for a person.
 *
 * The review token opens the form on a web page and may be passed on by an agent. The submit
 * token is only ever placed inside the form shown in the conversation.
 */
final class Draft
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public readonly string $reviewToken,
        public readonly string $submitToken,
        public readonly string $tool,
        public readonly array $values,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
