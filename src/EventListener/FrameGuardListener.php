<?php

declare(strict_types=1);

namespace FormBridge\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Keeps FormBridge's own pages from being framed by another site.
 */
final class FrameGuardListener
{
    public function onResponse(ResponseEvent $event): void
    {
        if (!str_starts_with((string) $event->getRequest()->attributes->get('_route'), 'form_bridge_')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }
}
