<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use Mcp\Event\RequestEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resets the application's services before each request of the STDIO server.
 *
 * One process serves a whole conversation there, so entities and choice lists loaded for one
 * request would otherwise be reused for the next.
 */
final class FreshStateListener
{
    private bool $servesStdio = false;

    public function __construct(
        private readonly ResetInterface $servicesResetter,
    ) {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->servesStdio = 'mcp:server' === $event->getCommand()?->getName();
    }

    public function onMcpRequest(RequestEvent $event): void
    {
        if ($this->servesStdio) {
            $this->servicesResetter->reset();
        }
    }
}
