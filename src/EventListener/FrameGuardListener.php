<?php

declare(strict_types=1);

namespace FormBridge\EventListener;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Keeps FormBridge's own pages from being framed by another site.
 *
 * It goes by the controller, not by the route, so that renaming or re-importing the routes does
 * not switch it off. A policy the application has already set is kept and only added to.
 */
final class FrameGuardListener
{
    public function onResponse(ResponseEvent $event): void
    {
        $controller = $event->getRequest()->attributes->get('_controller');
        if (\is_array($controller)) {
            $controller = \is_object($controller[0] ?? null) ? $controller[0]::class : ($controller[0] ?? null);
        } elseif (\is_object($controller)) {
            $controller = $controller::class;
        }
        if (!\is_string($controller) || !str_starts_with(ltrim($controller, '\\'), 'FormBridge\\Controller\\')) {
            return;
        }

        self::guard($event->getResponse()->headers);
    }

    public static function guard(ResponseHeaderBag $headers): void
    {
        $policy = $headers->get('Content-Security-Policy');
        if (null === $policy || '' === trim($policy)) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        } elseif (!str_contains(strtolower($policy), 'frame-ancestors')) {
            $headers->set('Content-Security-Policy', rtrim(trim($policy), ';')."; frame-ancestors 'self'");
        }

        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
    }
}
