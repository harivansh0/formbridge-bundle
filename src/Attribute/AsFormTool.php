<?php

declare(strict_types=1);

namespace FormBridge\Attribute;

/**
 * Exposes the form handled by this service as a tool for AI agents.
 *
 * An agent can fill the form in and gets its validation back. The handler runs only when the
 * form is submitted. It may refuse by throwing a ValidationFailedException, before writing
 * anything: a refused form stays open and can be submitted again.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFormTool
{
    /**
     * @param string       $name        tool name, for example "create_booking"
     * @param class-string $form        the form type the handler works with
     * @param string       $description what the form does, written for the agent
     * @param string|null  $title       heading shown above the form; defaults to a readable version of the name
     * @param string       $submitLabel text of the button a person presses
     * @param string       $method      handler method that receives the form's data once it is valid
     */
    public function __construct(
        public string $name,
        public string $form,
        public string $description,
        public ?string $title = null,
        public string $submitLabel = 'Submit',
        public string $method = '__invoke',
    ) {
    }
}
