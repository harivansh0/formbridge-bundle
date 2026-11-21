<?php

declare(strict_types=1);

namespace FormBridge\Tests\Fixtures;

/**
 * An unnamed MCP tool is called after the class that declares __invoke(): "Order".
 */
class Order
{
    public function __invoke(): string
    {
        return 'Ordered.';
    }
}
