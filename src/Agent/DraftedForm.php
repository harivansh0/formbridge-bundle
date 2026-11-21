<?php

declare(strict_types=1);

namespace FormBridge\Agent;

use FormBridge\FormOutcome;

final readonly class DraftedForm
{
    /**
     * @param array<string, mixed> $arguments what the agent sent
     */
    public function __construct(
        public string $tool,
        public array $arguments,
        public FormOutcome $outcome,
    ) {
    }
}
