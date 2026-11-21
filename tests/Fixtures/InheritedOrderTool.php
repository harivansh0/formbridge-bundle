<?php

declare(strict_types=1);

namespace FormBridge\Tests\Fixtures;

final class InheritedOrderTool extends Order
{
    #[NamedTool(name: 'order_submit')]
    public function submit(): string
    {
        return 'Saved.';
    }
}
