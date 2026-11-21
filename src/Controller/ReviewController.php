<?php

declare(strict_types=1);

namespace FormBridge\Controller;

use FormBridge\DraftStore;
use FormBridge\FormToolRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * A drafted form on a web page, for clients that cannot show forms in the conversation.
 *
 * The link reaches the person through the agent, so posting to it also needs a CSRF token issued
 * when the page was loaded. That is not authentication: put this route behind the application's
 * access control to decide who may save.
 */
#[Route('/_formbridge/review/{token}', name: 'form_bridge_review', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
final class ReviewController
{
    private const PROOF_FIELD = '_formbridge_review';

    public function __construct(
        private readonly DraftStore $drafts,
        private readonly FormToolRunner $runner,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly bool $enabled = true,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }

        $gone = 'This link has expired or was already used.';
        $draft = $this->drafts->findByReviewToken($token) ?? throw new NotFoundHttpException($gone);
        $proofId = 'formbridge-review-'.$token;

        if ($request->isMethod('POST')) {
            $fields = $request->request->all();
            $proof = $fields[self::PROOF_FIELD] ?? null;
            unset($fields[self::PROOF_FIELD]);
            if (!\is_string($proof) || !$this->csrfTokens->isTokenValid(new CsrfToken($proofId, $proof))) {
                throw new AccessDeniedHttpException('Open the link in your browser and submit the form from there.');
            }

            $outcome = $this->drafts->claim($draft, fn () => $this->runner->submit($draft->tool, $fields))
                ?? throw new NotFoundHttpException($gone);
        } else {
            $outcome = $this->runner->review($draft->tool, $draft->values);
        }

        return new Response(
            $this->twig->render('@FormBridge/review.html.twig', [
                'outcome' => $outcome,
                'tool' => $outcome->tool,
                'form' => $outcome->form->createView(),
                'review_proof' => $outcome->saved ? null : $this->csrfTokens->getToken($proofId)->getValue(),
                'review_proof_field' => self::PROOF_FIELD,
            ]),
            $request->isMethod('POST') && !$outcome->saved ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
