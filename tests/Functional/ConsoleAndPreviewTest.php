<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Tests\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleAndPreviewTest extends FunctionalTestCase
{
    public function testTheDebugCommandListsEveryFormTool(): void
    {
        $command = $this->command();
        $command->execute([]);

        $command->assertCommandIsSuccessful();
        foreach (['place_order', 'place_order_submit', 'OrderType', 'Place an order', 'exposed'] as $expected) {
            self::assertStringContainsString($expected, $command->getDisplay());
        }
    }

    public function testTheDebugCommandShowsWhatAnAgentIsToldAboutOneTool(): void
    {
        $command = $this->command();
        $command->execute(['name' => 'place_order']);

        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('Order coffee beans.', $command->getDisplay());
        self::assertStringContainsString('"additionalProperties": false', $command->getDisplay());
        self::assertStringContainsString('espresso = \\"Espresso beans (3 left)\\"', $command->getDisplay());
    }

    public function testAnUnknownToolIsAnError(): void
    {
        $command = $this->command();

        self::assertSame(1, $command->execute(['name' => 'delete_everything']));
        self::assertStringContainsString('No form tool is named "delete_everything".', $command->getDisplay());
    }

    public function testThePreviewIsServedInDebugModeOnly(): void
    {
        $this->client->request('GET', '/_formbridge/preview');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');

        self::ensureKernelShutdown();
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/_formbridge/preview');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePreviewOnlyTalksToThisApplication(): void
    {
        $this->client->request('GET', '/_formbridge/preview', ['endpoint' => '//evil.example/mcp']);

        self::assertResponseStatusCodeSame(400);
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('debug:form-tools'));
    }
}
