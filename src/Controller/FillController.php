<?php

declare(strict_types=1);

namespace FormBridge\Controller;

use FormBridge\Ai\FormFiller;
use FormBridge\DraftStore;
use FormBridge\Form\Type\MessageType;
use FormBridge\FormToolRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * "Paste a message": free text is read into a form by a model, then reviewed and submitted by a
 * person on the review page.
 */
#[Route('/_formbridge/fill/{tool}', name: 'form_bridge_fill', methods: ['GET', 'POST'])]
final class FillController
{
    public function __construct(
        private readonly ?FormFiller $filler,
        private readonly FormToolRegistry $tools,
        private readonly DraftStore $drafts,
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly bool $debug = false,
        private readonly ?RateLimiterFactoryInterface $limiter = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(Request $request, string $tool): Response
    {
        if (null === $this->filler || !$this->tools->has($tool)) {
            throw new NotFoundHttpException();
        }
        $formTool = $this->tools->get($tool);

        $form = $this->forms->createNamed('message', MessageType::class, ['text' => $request->query->getString('text')]);
        $form->handleRequest($request);
        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $this->limiter?->create($request->getClientIp())->consume();
            if (false === $limit?->isAccepted()) {
                throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many messages in a short time. Try again in a moment.');
            }

            try {
                $outcome = $this->filler->fill($tool, $form->get('text')->getData());

                return new RedirectResponse($this->urls->generate('form_bridge_review', [
                    'token' => $this->drafts->open($tool, $outcome->values)->reviewToken,
                ]));
            } catch (\Throwable $error) {
                $this->logger->error('The message could not be read into "{tool}": {message}', ['tool' => $tool, 'message' => $error->getMessage(), 'exception' => $error]);
                $form->addError(new FormError('The message could not be read right now.'.($this->debug ? ' '.$error->getMessage() : '')));
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        return new Response(
            $this->twig->render('@FormBridge/fill.html.twig', ['tool' => $formTool, 'form' => $form->createView()]),
            $status,
        );
    }
}
