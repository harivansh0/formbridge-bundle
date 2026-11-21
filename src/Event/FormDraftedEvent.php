<?php

declare(strict_types=1);

namespace FormBridge\Event;

use FormBridge\FormOutcome;

/**
 * An agent, or a model reading free text, filled a form in. Nothing was saved.
 */
final class FormDraftedEvent
{
    public function __construct(
        public readonly FormOutcome $outcome,
    ) {
    }
}
