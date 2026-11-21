<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use FormBridge\Draft;
use FormBridge\DraftStore;
use FormBridge\FormOutcome;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Builds tool results: text for the model, structured data for programs, and the rendered form
 * for the person.
 */
final class FormToolResponder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly DraftStore $drafts,
        private readonly UrlGeneratorInterface $urls,
        private readonly bool $reviewPage = true,
    ) {
    }

    /**
     * Opens a draft for the person and answers the agent.
     *
     * @param array<string, mixed> $values what the agent sent
     */
    public function forDraft(FormOutcome $outcome, array $values): CallToolResult
    {
        $draft = $this->drafts->open($outcome->tool->name, $values);
        $reviewUrl = $this->reviewPage
            ? $this->urls->generate('form_bridge_review', ['token' => $draft->reviewToken], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        return new CallToolResult(
            content: [new TextContent($outcome->toText($reviewUrl))],
            structuredContent: $outcome->toArray() + (null === $reviewUrl ? [] : ['reviewUrl' => $reviewUrl]),
            meta: [FormBridgeApp::HTML_META_KEY => $this->render($outcome, $draft)],
        );
    }

    public function forSubmit(FormOutcome $outcome, Draft $draft): CallToolResult
    {
        return new CallToolResult(
            content: [new TextContent($outcome->toText())],
            structuredContent: $outcome->toArray(),
            meta: [FormBridgeApp::HTML_META_KEY => $this->render($outcome, $draft)],
        );
    }

    private function render(FormOutcome $outcome, Draft $draft): string
    {
        return $this->twig->render('@FormBridge/_form.html.twig', [
            'outcome' => $outcome,
            'tool' => $outcome->tool,
            'form' => $outcome->form->createView(),
            'submit_token' => $outcome->saved ? null : $draft->submitToken,
        ]);
    }
}
