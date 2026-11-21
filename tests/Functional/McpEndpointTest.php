<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Tests\App\Orders;
use FormBridge\Tests\FunctionalTestCase;

/**
 * The exchange as an MCP client sees it over HTTP.
 */
final class McpEndpointTest extends FunctionalTestCase
{
    private const POSTED = ['product' => 'espresso', 'quantity' => '2', 'email' => 'mia@example.com', 'delivery[street]' => '', 'delivery[city]' => 'Lyon'];

    private ?string $sessionId = null;
    private int $requestId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ]);
        $this->sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->rpc('notifications/initialized', [], notification: true);
    }

    public function testEachFormGetsAToolForTheModelAndASubmitToolForTheFormOnly(): void
    {
        $tools = array_column($this->rpc('tools/list', [])['tools'], null, 'name');

        self::assertSame(['place_order', 'place_order_submit'], array_keys($tools));
        self::assertSame(['model', 'app'], $tools['place_order']['_meta']['ui']['visibility']);
        self::assertSame(['app'], $tools['place_order_submit']['_meta']['ui']['visibility']);
        self::assertSame('ui://formbridge-form', $tools['place_order']['_meta']['ui']['resourceUri']);
        self::assertTrue($tools['place_order']['annotations']['readOnlyHint']);
        self::assertTrue($tools['place_order_submit']['annotations']['destructiveHint']);
    }

    public function testADraftIsAnsweredWithTextDataAndTheRenderedForm(): void
    {
        $result = $this->call('place_order', ['quantity' => 9] + self::ORDER);

        self::assertFalse($result['isError']);
        self::assertStringContainsString('The "Place an order" form is not valid yet. Nothing is saved.', $result['content'][0]['text']);
        self::assertFalse($result['structuredContent']['valid']);
        self::assertFalse($result['structuredContent']['saved']);
        self::assertSame('quantity', $result['structuredContent']['errors'][0]['field']);
        self::assertStringContainsString('/_formbridge/review/', $result['structuredContent']['reviewUrl']);
        self::assertStringContainsString('data-call="place_order_submit"', $result['_meta']['formbridge/html']);
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
    }

    public function testTheSubmitTokenIsOnlyInTheFormAndNeverInWhatTheModelReads(): void
    {
        $result = $this->call('place_order', self::ORDER);
        $token = $this->tokenIn($result);

        self::assertStringNotContainsString($token, json_encode([$result['content'], $result['structuredContent']], \JSON_THROW_ON_ERROR));
    }

    public function testSavingWithoutTheFormsTokenIsRefused(): void
    {
        $result = $this->call('place_order_submit', self::POSTED);

        self::assertTrue($result['isError']);
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
    }

    public function testThePersonSubmittingTheFormSavesOnce(): void
    {
        $draft = $this->call('place_order', self::ORDER);
        $fields = ['_formbridge_token' => $this->tokenIn($draft)] + self::POSTED;

        $saved = $this->call('place_order_submit', $fields);
        self::assertTrue($saved['structuredContent']['saved']);
        self::assertSame('Order #1: 2 × espresso to Lyon.', $saved['structuredContent']['result']);

        $again = $this->call('place_order_submit', $fields);
        self::assertTrue($again['isError']);
        self::assertCount(1, self::getContainer()->get(Orders::class)->all());
    }

    public function testAFormTheHandlerRefusedStaysOpenForCorrection(): void
    {
        $draft = $this->call('place_order', self::ORDER);
        $fields = ['_formbridge_token' => $this->tokenIn($draft)] + self::POSTED;
        Orders::reset(espresso: 1);

        $refused = $this->call('place_order_submit', $fields);
        self::assertFalse($refused['structuredContent']['saved']);
        self::assertSame('Only 1 left.', $refused['structuredContent']['errors'][0]['message']);

        $saved = $this->call('place_order_submit', ['quantity' => '1'] + $fields);
        self::assertTrue($saved['structuredContent']['saved']);
    }

    public function testAnUnknownFieldAtTheTopOrInsideAGroupIsRejectedBeforeTheFormRuns(): void
    {
        foreach ([['coupon' => 'FREE'], ['delivery' => ['city' => 'Lyon', 'floor' => '3']]] as $extra) {
            $message = ['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => 'tools/call', 'params' => ['name' => 'place_order', 'arguments' => $extra + self::ORDER]];
            $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode($message, \JSON_THROW_ON_ERROR));

            self::assertStringContainsString('Invalid parameters for tool', (string) $this->client->getInternalResponse()->getContent());
        }
    }

    public function testTheFormScreenIsServedAsAnMcpApp(): void
    {
        $content = $this->rpc('resources/read', ['uri' => 'ui://formbridge-form'])['contents'][0];

        self::assertSame('text/html;profile=mcp-app', $content['mimeType']);
        self::assertStringContainsString('ui/initialize', $content['text']);
        self::assertStringContainsString('Object.create(null)', $content['text']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params, bool $notification = false): array
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params];
        if (!$notification) {
            $message['id'] = ++$this->requestId;
        }

        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode($message, \JSON_THROW_ON_ERROR));
        if ($notification) {
            return [];
        }

        // One JSON document, either as the body or as a server-sent event.
        $body = (string) $this->client->getInternalResponse()->getContent();
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = (string) preg_replace('/^data:\s*/', '', $line);
            if (!str_starts_with($line, '{')) {
                continue;
            }
            $answer = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (($answer['id'] ?? null) === $this->requestId) {
                self::assertArrayNotHasKey('error', $answer, $method.' was rejected: '.json_encode($answer['error'] ?? null));

                return $answer['result'];
            }
        }

        self::fail(\sprintf('No answer to "%s" (HTTP %d): %s', $method, $this->client->getResponse()->getStatusCode(), $body));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $this->sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->sessionId;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function tokenIn(array $result): string
    {
        self::assertSame(1, preg_match('/name="_formbridge_token" value="([a-f0-9]{32})"/', $result['_meta']['formbridge/html'], $match));

        return $match[1];
    }
}
