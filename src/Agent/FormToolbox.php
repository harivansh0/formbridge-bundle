<?php

declare(strict_types=1);

namespace FormBridge\Agent;

use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

/**
 * Adds every form tool to the toolbox of a Symfony AI agent.
 *
 * Calling a form tool fills the form in and validates it. There is no tool for saving.
 */
final class FormToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly FormToolRegistry $tools,
        private readonly FormToolRunner $runner,
        private readonly FormToolSchemas $schemas,
        private readonly DraftLog $drafts,
        private readonly ?ToolboxInterface $inner = null,
    ) {
    }

    public function getTools(): array
    {
        $tools = $this->inner?->getTools() ?? [];
        foreach ($this->schemas->all() as [$tool, $schema]) {
            $tools[] = new Tool(
                new ExecutionReference(self::class, 'execute'),
                $tool->name,
                $tool->agentDescription(),
                // A form's schema has no "required" list, which the parameter type expects.
                $schema, // @phpstan-ignore argument.type
                effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
            );
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->getName();
        if (!$this->tools->has($name) || null !== $this->schemas->problemWith($this->tools->get($name))) {
            return $this->inner?->execute($toolCall) ?? throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $outcome = $this->runner->draft($toolCall->getName(), $toolCall->getArguments());
        $this->drafts->record(new DraftedForm($toolCall->getName(), $toolCall->getArguments(), $outcome));

        return new ToolResult($toolCall, $outcome->toText());
    }
}
