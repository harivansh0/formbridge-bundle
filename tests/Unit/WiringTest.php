<?php

declare(strict_types=1);

namespace FormBridge\Tests\Unit;

use FormBridge\DependencyInjection\FormToolPass;
use FormBridge\FormToolRegistry;
use FormBridge\Mcp\FormToolLoader;
use FormBridge\Mcp\FreshStateListener;
use FormBridge\Tests\Fixtures\InheritedOrderTool;
use FormBridge\Tests\Fixtures\OrderSubmitTool;
use Mcp\Event\RequestEvent;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Contracts\Service\ResetInterface;

final class WiringTest extends TestCase
{
    public function testTwoFormsCannotEndUpWithTheSameToolName(): void
    {
        $container = new ContainerBuilder();
        $container->register('form_bridge.registry', FormToolRegistry::class)->setArguments([[], null]);
        foreach (['order', 'order_submit'] as $name) {
            $container->register('handler.'.$name, \stdClass::class)->addTag(FormToolPass::TAG, [
                'name' => $name, 'form' => 'Form', 'description' => '', 'title' => null, 'submit_label' => 'Send', 'method' => '__invoke',
            ]);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"order_submit"');

        (new FormToolPass())->process($container);
    }

    public function testAnOrdinaryMcpToolCannotTakeTheNameOfAFormTool(): void
    {
        $container = new ContainerBuilder();
        $container->register('form_bridge.registry', FormToolRegistry::class)->setArguments([[], null]);
        $container->register('handler.order', \stdClass::class)->addTag(FormToolPass::TAG, [
            'name' => 'order', 'form' => 'Form', 'description' => '', 'title' => null, 'submit_label' => 'Send', 'method' => '__invoke',
        ]);
        $container->register(OrderSubmitTool::class)->addTag('mcp.tool', ['method' => '__invoke']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The MCP tool "order_submit"');

        (new FormToolPass())->process($container);
    }

    public function testAClashIsFoundTheWayTheMcpBundleNamesTools(): void
    {
        // A tool named through an attribute that extends #[McpTool].
        $derived = $this->containerWithFormTool('order');
        $derived->register(InheritedOrderTool::class)->addTag('mcp.tool', ['method' => 'submit']);

        try {
            (new FormToolPass())->process($derived);
            self::fail('A tool named by a derived attribute took the name of a submit tool.');
        } catch (LogicException $clash) {
            self::assertStringContainsString('"order_submit"', $clash->getMessage());
        }

        // An unnamed __invoke() is called after the class that declares it, here "Order".
        $inherited = $this->containerWithFormTool('Order');
        $inherited->register(InheritedOrderTool::class)->addTag('mcp.tool', ['method' => '__invoke']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"Order"');

        (new FormToolPass())->process($inherited);
    }

    public function testAnAbstractToolDefinitionCannotClash(): void
    {
        $container = $this->containerWithFormTool('order');
        $container->register(OrderSubmitTool::class)->setAbstract(true)->addTag('mcp.tool', ['method' => '__invoke']);

        (new FormToolPass())->process($container);

        self::assertArrayHasKey('order', $container->getDefinition('form_bridge.registry')->getArgument(0));
    }

    private function containerWithFormTool(string $name): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('form_bridge.registry', FormToolRegistry::class)->setArguments([[], null]);
        $container->register('handler', \stdClass::class)->addTag(FormToolPass::TAG, [
            'name' => $name, 'form' => 'Form', 'description' => '', 'title' => null, 'submit_label' => 'Send', 'method' => '__invoke',
        ]);

        return $container;
    }

    public function testFormToolsCanBeLimitedToNamedMcpServers(): void
    {
        $container = new ContainerBuilder();
        $container->register('form_bridge.registry', FormToolRegistry::class)->setArguments([[], null]);
        $container->register('form_bridge.mcp.loader', FormToolLoader::class)->addTag('mcp.loader');
        $container->register('mcp.server.public.builder', \stdClass::class);
        $container->register('mcp.server.staff.builder', \stdClass::class);
        $container->setParameter(FormToolPass::SERVERS_PARAMETER, ['staff']);

        (new FormToolPass())->process($container);

        self::assertFalse($container->getDefinition('form_bridge.mcp.loader')->hasTag('mcp.loader'));
        self::assertSame([], $container->getDefinition('mcp.server.public.builder')->getMethodCalls());
        self::assertSame('addLoaders', $container->getDefinition('mcp.server.staff.builder')->getMethodCalls()[0][0]);
    }

    public function testAnUnknownMcpServerIsAConfigurationError(): void
    {
        $container = new ContainerBuilder();
        $container->register('form_bridge.registry', FormToolRegistry::class)->setArguments([[], null]);
        $container->register('form_bridge.mcp.loader', FormToolLoader::class)->addTag('mcp.loader');
        $container->setParameter(FormToolPass::SERVERS_PARAMETER, ['staff']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"staff"');

        (new FormToolPass())->process($container);
    }

    public function testALongRunningServerStartsEachRequestFromFreshState(): void
    {
        $resetter = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $listener = new FreshStateListener($resetter);
        $request = new RequestEvent(new ListToolsRequest(), self::createStub(SessionInterface::class));

        // Over HTTP every request already starts fresh.
        $listener->onMcpRequest($request);
        self::assertSame(0, $resetter->resets);

        // "bin/console mcp:server" serves a whole conversation from one process.
        $listener->onConsoleCommand(new ConsoleCommandEvent(new Command('mcp:server'), new ArrayInput([]), new NullOutput()));
        $listener->onMcpRequest($request);
        $listener->onMcpRequest($request);
        self::assertSame(2, $resetter->resets);
    }
}
