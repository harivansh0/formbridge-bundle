<?php

declare(strict_types=1);

namespace FormBridge\Event;

use FormBridge\FormOutcome;

/**
 * A form was submitted through FormBridge and the application's handler saved it.
 */
final class FormSavedEvent
{
    /**
     * @param mixed $data   the form data the handler received
     * @param mixed $result what the handler returned
     */
    public function __construct(
        public readonly FormOutcome $outcome,
        public readonly mixed $data,
        public readonly mixed $result,
    ) {
    }
}
