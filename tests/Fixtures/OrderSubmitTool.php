<?php

declare(strict_types=1);

namespace FormBridge\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * An ordinary MCP tool whose name clashes with the submit tool of a form called "order".
 */
final class OrderSubmitTool
{
    #[McpTool(name: 'order_submit')]
    public function __invoke(): string
    {
        return 'Saved.';
    }
}
