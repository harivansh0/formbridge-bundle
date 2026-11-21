<?php

declare(strict_types=1);

namespace FormBridge;

final class FormTool
{
    /**
     * @param class-string $form
     */
    public function __construct(
        public readonly string $name,
        public readonly string $form,
        public readonly string $description,
        public readonly string $title,
        public readonly string $submitLabel,
        public readonly string $method,
    ) {
    }

    /**
     * The companion tool that only the form shown to the person may call.
     */
    public function submitToolName(): string
    {
        return $this->name.'_submit';
    }

    /**
     * Asks the agent to fill the form in straight away. A careful model would otherwise stop to
     * ask about a value that looks unavailable; the form shows that next to the alternatives.
     */
    public function agentDescription(): string
    {
        return $this->description
            .' Fills in the form and checks it against the application\'s own rules; it does not save anything.'
            .' The filled-in form is shown to the user, who reviews it, corrects it and presses "'.$this->submitLabel.'" themselves.'
            .' Call it as soon as the user asks, with every value you know, and leave out what you do not know.'
            .' Call it even when a value looks unavailable or a detail is missing: the form shows the problem and the user fixes it there, which is quicker for them than answering questions first.';
    }
}
