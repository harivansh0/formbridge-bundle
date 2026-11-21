<?php

declare(strict_types=1);

namespace FormBridge\DependencyInjection;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

final class FormToolPass implements CompilerPassInterface
{
    public const TAG = 'form_bridge.tool';
    public const SERVERS_PARAMETER = 'form_bridge.mcp_servers';

    private const REGISTRY = 'form_bridge.registry';
    private const LOADER = 'form_bridge.mcp.loader';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::REGISTRY)) {
            return;
        }

        $definitions = [];
        $handlers = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $name = $tag['name'];
                if (isset($definitions[$name])) {
                    throw new LogicException(\sprintf('The form tool name "%s" is used twice; the second use is on service "%s".', $name, $serviceId));
                }

                $definitions[$name] = [
                    'form' => $tag['form'],
                    'description' => $tag['description'],
                    'title' => $tag['title'] ?? null,
                    'submit_label' => $tag['submit_label'],
                    'method' => $tag['method'],
                ];
                $handlers[$name] = new Reference($serviceId);
            }
        }

        foreach (array_keys($definitions) as $name) {
            if (isset($definitions[$name.'_submit'])) {
                throw new LogicException(\sprintf('The form tool "%s" also gets a tool named "%s_submit", which is the name of another form tool. Rename one of them.', $name, $name));
            }
        }

        $this->rejectNamesOfOtherMcpTools($container, array_keys($definitions));
        $this->limitToConfiguredServers($container);

        $container->getDefinition(self::REGISTRY)
            ->replaceArgument(0, $definitions)
            ->replaceArgument(1, ServiceLocatorTagPass::register($container, $handlers));
    }

    /**
     * The MCP registry lets a later registration replace an earlier one without a word, and form
     * tools are registered first. Names are compared across all servers.
     *
     * @param list<string> $names
     */
    private function rejectNamesOfOtherMcpTools(ContainerBuilder $container, array $names): void
    {
        $taken = array_fill_keys([...$names, ...array_map(static fn (string $name): string => $name.'_submit', $names)], true);

        foreach ($container->findTaggedServiceIds('mcp.tool') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            if ($definition->isAbstract()) {
                continue;
            }
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId);
            $reflection = \is_string($class) ? $container->getReflectionClass($class, false) : null;
            if (null === $reflection) {
                continue;
            }

            foreach ($tags as $tag) {
                $method = $tag['method'] ?? '__invoke';
                if (!$reflection->hasMethod($method)) {
                    continue;
                }

                $reflectionMethod = $reflection->getMethod($method);
                $attribute = $reflectionMethod->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF)[0]
                    ?? $reflection->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF)[0]
                    ?? null;
                // The SDK names an unnamed __invoke() tool after the class that declares the method.
                $name = $attribute?->newInstance()->name ?? ('__invoke' === $method ? $reflectionMethod->getDeclaringClass()->getShortName() : $method);

                if (isset($taken[$name])) {
                    throw new LogicException(\sprintf('The MCP tool "%s" of service "%s" has the name of a form tool or of a form\'s submit tool. Rename one of them.', $name, $serviceId));
                }
            }
        }
    }

    /**
     * A tagged loader reaches every MCP server. With "form_bridge.mcp_servers" set, the form tools
     * go to the named servers only.
     */
    private function limitToConfiguredServers(ContainerBuilder $container): void
    {
        $servers = $container->hasParameter(self::SERVERS_PARAMETER) ? (array) $container->getParameter(self::SERVERS_PARAMETER) : [];
        if ([] === $servers || !$container->hasDefinition(self::LOADER)) {
            return;
        }

        $container->getDefinition(self::LOADER)->clearTag('mcp.loader');

        foreach ($servers as $server) {
            $builderId = \sprintf('mcp.server.%s.builder', $server);
            if (!$container->hasDefinition($builderId)) {
                throw new LogicException(\sprintf('"form_bridge.mcp_servers" names the MCP server "%s", which is not configured under "mcp.servers".', $server));
            }

            $container->getDefinition($builderId)->addMethodCall('addLoaders', [[new Reference(self::LOADER)]]);
        }
    }
}
