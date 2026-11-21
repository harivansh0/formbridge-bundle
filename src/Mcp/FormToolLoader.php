<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use FormBridge\DraftStore;
use FormBridge\FormTool;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * Registers two MCP tools per form: "<name>" for the model, which only validates, and
 * "<name>_submit" for the form shown to the person, which runs the handler.
 */
final class FormToolLoader implements LoaderInterface
{
    private const RESULT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'tool' => ['type' => 'string'],
            'valid' => ['type' => 'boolean', 'description' => 'Whether the form accepts the values as they are.'],
            'saved' => ['type' => 'boolean', 'description' => 'True only after the user submitted the form.'],
            'values' => ['type' => 'object', 'description' => 'The values the form holds now.'],
            'errors' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string', 'description' => 'Field the message is about; empty for the form as a whole.'],
                        'message' => ['type' => 'string'],
                        'choices' => [
                            'type' => 'array',
                            'description' => 'For a choice field: the options the form offers right now.',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['value' => ['type' => 'string'], 'label' => ['type' => 'string']],
                                'required' => ['value', 'label'],
                            ],
                        ],
                    ],
                    'required' => ['field', 'message'],
                ],
            ],
            'result' => ['type' => 'string'],
            'reviewUrl' => ['type' => 'string', 'description' => 'The same form on a web page, for clients that cannot show it in the chat.'],
        ],
        'required' => ['tool', 'valid', 'saved', 'values', 'errors'],
    ];

    public function __construct(
        private readonly FormToolSchemas $schemas,
        private readonly FormToolRunner $runner,
        private readonly FormToolResponder $responder,
        private readonly DraftStore $drafts,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $entries = [];
        foreach ($this->schemas->all() as [$tool, $schema]) {
            $entries[] = ['definition' => $this->draftTool($tool, $schema), 'handler' => new DraftToolHandler($tool->name, $this->runner, $this->responder)];
            $entries[] = ['definition' => $this->submitTool($tool), 'handler' => new SubmitToolHandler($tool->name, $this->runner, $this->responder, $this->drafts)];
        }

        (new ExplicitElementLoader(tools: $entries))->load($registry);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function draftTool(FormTool $tool, array $schema): Tool
    {
        return new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $schema,
            description: $tool->agentDescription(),
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
            meta: ['ui' => new UiToolMeta(FormBridgeApp::URI, [ToolVisibility::Model, ToolVisibility::App])],
            outputSchema: self::RESULT_SCHEMA,
        );
    }

    private function submitTool(FormTool $tool): Tool
    {
        return new Tool(
            name: $tool->submitToolName(),
            title: $tool->submitLabel,
            // Posted form fields; the form validates them.
            inputSchema: ['type' => 'object', 'additionalProperties' => true],
            description: 'Used by the form shown to the user when they press "'.$tool->submitLabel.'". Not for the model.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
            meta: ['ui' => new UiToolMeta(FormBridgeApp::URI, [ToolVisibility::App])],
            outputSchema: self::RESULT_SCHEMA,
        );
    }
}
