<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use Symfony\AI\McpBundle\Attribute\AsMcpApp;

/**
 * The MCP App every form tool points at: one static shell. The rendered form arrives with each
 * tool result.
 */
#[AsMcpApp(
    uri: self::URI,
    title: 'Form',
    template: '@FormBridge/app.html.twig',
    prefersBorder: true,
)]
final class FormBridgeApp
{
    public const URI = 'ui://formbridge-form';

    /** The rendered form travels in `_meta`, which hosts keep out of the model's context. */
    public const HTML_META_KEY = 'formbridge/html';
}
