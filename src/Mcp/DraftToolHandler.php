<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use FormBridge\FormToolRunner;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * The tool a model calls: fills the form in and validates it. Never saves.
 */
final class DraftToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly string $toolName,
        private readonly FormToolRunner $runner,
        private readonly FormToolResponder $responder,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return $this->responder->forDraft($this->runner->draft($this->toolName, $arguments), $arguments);
    }
}
