<?php

declare(strict_types=1);

namespace FormBridge\Event;

use FormBridge\FormTool;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A valid form is about to be handed to its handler.
 *
 * A listener may refuse it, for example because the current user may not use this form. The
 * handler then does not run, and the message is shown on the form.
 */
final class FormSubmitEvent implements StoppableEventInterface
{
    private ?string $refusal = null;

    public function __construct(
        public readonly FormTool $tool,
        public readonly mixed $data,
    ) {
    }

    public function refuse(string $message): void
    {
        $this->refusal = $message;
    }

    public function getRefusal(): ?string
    {
        return $this->refusal;
    }

    public function isPropagationStopped(): bool
    {
        return null !== $this->refusal;
    }
}
