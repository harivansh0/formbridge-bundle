<?php

declare(strict_types=1);

namespace FormBridge;

use Symfony\Component\Form\FormInterface;

/**
 * What a form made of the values it was given.
 */
final class FormOutcome
{
    /**
     * @param array<string, mixed>                                                                             $values
     * @param list<array{field: string, message: string, choices?: list<array{value: string, label: string}>}> $errors
     * @param bool                                                                                             $saved  true only when the handler ran
     */
    public function __construct(
        public readonly FormTool $tool,
        public readonly FormInterface $form,
        public readonly bool $valid,
        public readonly bool $saved,
        public readonly array $values,
        public readonly array $errors,
        public readonly ?string $result = null,
    ) {
    }

    /**
     * @return array{tool: string, valid: bool, saved: bool, values: array<string, mixed>|\stdClass, errors: list<array<string, mixed>>, result?: string}
     */
    public function toArray(): array
    {
        $data = [
            'tool' => $this->tool->name,
            'valid' => $this->valid,
            'saved' => $this->saved,
            // An empty PHP array would be encoded as a JSON list.
            'values' => [] === $this->values ? new \stdClass() : $this->values,
            'errors' => $this->errors,
        ];
        if (null !== $this->result) {
            $data['result'] = $this->result;
        }

        return $data;
    }

    /**
     * The outcome as the model reads it.
     */
    public function toText(?string $reviewUrl = null): string
    {
        if ($this->saved) {
            return 'Saved by the user. '.$this->result;
        }

        $lines = [];
        if ($this->valid) {
            $lines[] = \sprintf('The "%s" form is filled in and valid. Nothing is saved yet.', $this->tool->title);
        } else {
            $lines[] = \sprintf('The "%s" form is not valid yet. Nothing is saved.', $this->tool->title);
            foreach ($this->errors as $error) {
                $line = '- '.('' === $error['field'] ? 'form' : $error['field']).': '.$error['message'];
                if (isset($error['choices'])) {
                    $line .= ' Options now: '.implode('; ', array_map(
                        static fn (array $choice): string => $choice['value'].' = '.$choice['label'],
                        $error['choices'],
                    )).'.';
                }
                $lines[] = $line;
            }
            $lines[] = 'You may call this tool again with corrected values, or leave the correction to the user.';
        }

        $lines[] = \sprintf('Saving is the user\'s step: they press "%s" in the form shown to them.', $this->tool->submitLabel);
        if (null !== $reviewUrl) {
            $lines[] = 'If no form is visible in the conversation, give the user this link to the same form: '.$reviewUrl;
        }
        $lines[] = 'Until the user presses the button nothing has been booked, changed or saved. Tell them the form is filled in and waiting for them; never say or imply that it is done.';

        return implode("\n", $lines);
    }
}
