<?php

declare(strict_types=1);

namespace FormBridge\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * An application's own attribute built on #[McpTool], as the MCP bundle allows.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NamedTool extends McpTool
{
}
