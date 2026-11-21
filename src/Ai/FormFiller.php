<?php

declare(strict_types=1);

namespace FormBridge\Ai;

use FormBridge\FormOutcome;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Fills a form in from free text, such as a customer's email.
 *
 * The model is asked for structured output in the form's own schema; the result goes through
 * the form like any other draft. Nothing is saved.
 */
final class FormFiller
{
    /**
     * @param non-empty-string $model a model that supports structured output
     */
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model,
        private readonly FormToolRegistry $tools,
        private readonly FormToolRunner $runner,
        private readonly FormToolSchemas $schemas,
    ) {
    }

    public function fill(string $toolName, string $text): FormOutcome
    {
        $tool = $this->tools->get($toolName);
        $schema = $this->schemas->of($tool);

        $messages = new MessageBag(
            Message::forSystem(
                'You fill in the form "'.$tool->title.'" ('.$tool->description.') from a message a person wrote.'
                .' Use only what the message says. Leave out every field the message does not answer; never guess or invent a value.'
                .' A person checks the form afterwards and fills in the rest.',
            ),
            Message::ofUser($text),
        );

        $result = $this->platform->invoke($this->model, $messages, [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $tool->name, 'schema' => $schema],
            ],
        ])->getResult();

        return $this->runner->draft($toolName, $this->values($result->getContent(), $schema));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function values(mixed $content, array $schema): array
    {
        if (\is_string($content)) {
            // Some models wrap JSON in a Markdown code fence.
            $content = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($content)) ?? '', true);
        }
        if (\is_object($content)) {
            $content = json_decode(json_encode($content, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        }

        return \is_array($content) ? $this->ownFields($content, $schema) : [];
    }

    /**
     * Keeps only values the form has a field for, at every level.
     *
     * @param array<mixed>         $values
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function ownFields(array $values, array $schema): array
    {
        $kept = [];
        foreach ((array) ($schema['properties'] ?? []) as $name => $field) {
            $value = $values[$name] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            $kept[(string) $name] = 'object' === ($field['type'] ?? null) && \is_array($value) ? $this->ownFields($value, $field) : $value;
        }

        return $kept;
    }
}
