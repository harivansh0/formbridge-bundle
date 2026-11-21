<?php

declare(strict_types=1);

namespace FormBridge\Mcp;

use FormBridge\DraftStore;
use FormBridge\FormToolRunner;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tool the form shown to the person calls. It needs the token that form carries.
 */
final class SubmitToolHandler implements ToolHandlerInterface
{
    public const TOKEN_FIELD = '_formbridge_token';

    public function __construct(
        private readonly string $toolName,
        private readonly FormToolRunner $runner,
        private readonly FormToolResponder $responder,
        private readonly DraftStore $drafts,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        // Not every host hides app-only tools from the model, so visibility alone is not trusted.
        $token = $arguments[self::TOKEN_FIELD] ?? null;
        unset($arguments[self::TOKEN_FIELD]);
        $draft = $this->drafts->findBySubmitToken($token, $this->toolName);

        // Fields arrive as a browser posts them: flat names such as "address[city]".
        parse_str(http_build_query($arguments), $values);
        /** @var array<string, mixed> $values */
        $outcome = null === $draft ? null : $this->drafts->claim($draft, fn () => $this->runner->submit($this->toolName, $values));

        if (null === $draft || null === $outcome) {
            $closed = 'This form is no longer open: it was already saved, or it expired. Ask the assistant to fill it in again.';

            return CallToolResult::error([new TextContent($this->translator?->trans($closed, [], 'form_bridge') ?? $closed)]);
        }

        return $this->responder->forSubmit($outcome, $draft);
    }
}
