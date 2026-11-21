<?php

declare(strict_types=1);

namespace FormBridge\Tests\Unit;

use FormBridge\Controller\ReviewController;
use FormBridge\EventListener\FrameGuardListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class FrameGuardListenerTest extends TestCase
{
    public function testAPageOfTheBundleIsGuardedWhateverItsRouteIsCalled(): void
    {
        $response = $this->respond(ReviewController::class, 'shop_review_renamed_by_the_application');

        self::assertSame("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testOtherPagesAreLeftAlone(): void
    {
        $response = $this->respond('App\Controller\ShopController::index', 'form_bridge_looking_route_name');

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testAPolicyTheApplicationSetIsKeptAndOnlyAddedTo(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none';");
        $this->respond(ReviewController::class, 'form_bridge_review', $response);

        self::assertSame("default-src 'self'; script-src 'none'; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testAStricterFramingRuleOfTheApplicationWins(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');
        $this->respond(ReviewController::class, 'form_bridge_review', $response);

        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    private function respond(string $controller, string $route, ?Response $response = null): Response
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);
        $request->attributes->set('_route', $route);
        $response ??= new Response();

        (new FrameGuardListener())->onResponse(new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
