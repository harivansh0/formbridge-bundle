<?php

declare(strict_types=1);

namespace FormBridge\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * A minimal MCP Apps host for trying the form tools in a browser. Debug mode only.
 */
#[Route('/_formbridge/preview', name: 'form_bridge_preview', methods: ['GET'])]
final class PreviewController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->debug) {
            throw new NotFoundHttpException();
        }

        // Only a path of this application: the page must never be pointed at another server.
        $endpoint = $request->query->getString('endpoint', '/mcp');
        if (1 !== preg_match('#^/[A-Za-z0-9_\-./]*$#', $endpoint) || str_starts_with($endpoint, '//')) {
            throw new BadRequestHttpException('The endpoint must be a path of this application, for example "/mcp".');
        }

        return new Response($this->twig->render('@FormBridge/preview.html.twig', ['endpoint' => $endpoint]));
    }
}
