<?php

declare(strict_types=1);

namespace FormBridge;

use FormBridge\Agent\FormToolbox;
use FormBridge\Ai\FormFiller;
use FormBridge\Attribute\AsFormTool;
use FormBridge\Controller\FillController;
use FormBridge\DependencyInjection\FormToolPass;
use FormBridge\Form\ConstraintDescriberInterface;
use FormBridge\Form\FieldDescriberInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class FormBridgeBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('agents')
                    ->info('Symfony AI agents, by their name under "ai.agent", that get every form tool. Each of them needs "tools" enabled.')
                    ->example(['assistant'])
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('mcp_servers')
                    ->info('MCP servers, by their name under "mcp.servers", that expose the form tools. Empty: every server.')
                    ->example(['default'])
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->booleanNode('review_page')
                    ->info('Whether a filled-in form is also offered as a link to a web page, for clients that cannot show forms.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('drafts')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')
                            ->info('Service id of the PSR-6 cache pool that keeps filled-in forms. Defaults to a pool of its own on "cache.app".')
                            ->defaultNull()
                        ->end()
                        ->integerNode('lifetime')
                            ->info('Seconds a filled-in form stays open for the person to submit.')
                            ->defaultValue(3600)
                            ->min(60)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('fill')
                    ->info('Filling a form in from free text with a Symfony AI model. Adds a "paste a message" page for every form tool.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('platform')->info('Service id of the Symfony AI platform to use.')->example('ai.platform.openai')->end()
                        ->scalarNode('model')->info('Name of a model that supports structured output.')->end()
                        ->scalarNode('rate_limiter')->info('Name of a limiter under "framework.rate_limiter", applied per client address.')->defaultNull()->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $fill): bool => $fill['enabled'] && (empty($fill['platform']) || empty($fill['model'])))
                        ->thenInvalid('Filling forms from text needs both "platform" and "model".')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['fill']['enabled'] && !$config['review_page'])
                ->thenInvalid('"form_bridge.fill" shows its result on the review page, so "form_bridge.review_page" must stay enabled.')
            ->end();
    }

    /**
     * @param array{agents: list<string>, mcp_servers: list<string>, review_page: bool, drafts: array{cache_pool: ?string, lifetime: int}, fill: array{enabled: bool, platform?: string, model?: string, rate_limiter?: ?string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('form_bridge.drafts.lifetime', $config['drafts']['lifetime'])
            ->set('form_bridge.review_page', $config['review_page'])
            ->set(FormToolPass::SERVERS_PARAMETER, $config['mcp_servers']);
        $container->import('../config/services.php');

        if (null !== $config['drafts']['cache_pool']) {
            $builder->getDefinition('form_bridge.draft_store')->replaceArgument(0, new Reference($config['drafts']['cache_pool']));
        }

        $builder->registerForAutoconfiguration(FieldDescriberInterface::class)->addTag('form_bridge.field_describer');
        $builder->registerForAutoconfiguration(ConstraintDescriberInterface::class)->addTag('form_bridge.constraint_describer');

        $builder->registerAttributeForAutoconfiguration(
            AsFormTool::class,
            static function (ChildDefinition $definition, AsFormTool $attribute): void {
                $definition->addTag(FormToolPass::TAG, [
                    'name' => $attribute->name,
                    'form' => $attribute->form,
                    'description' => $attribute->description,
                    'title' => $attribute->title,
                    'submit_label' => $attribute->submitLabel,
                    'method' => $attribute->method,
                ]);
            },
        );

        if ($config['fill']['enabled']) {
            $this->registerFormFiller($config['fill'], $container, $builder);
        }

        if ([] !== $config['agents']) {
            $this->registerAgentToolboxes($config['agents'], $container);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new FormToolPass());
    }

    /**
     * @param array{platform?: string, model?: string, rate_limiter?: ?string} $fill
     */
    private function registerFormFiller(array $fill, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!interface_exists(PlatformInterface::class)) {
            throw new LogicException('Filling forms from text requires the Platform component. Try running "composer require symfony/ai-platform".');
        }

        $services = $container->services();
        $services->set('form_bridge.form_filler', FormFiller::class)
            ->args([
                service($fill['platform'] ?? ''),
                $fill['model'] ?? '',
                service('form_bridge.registry'),
                service('form_bridge.runner'),
                service('form_bridge.tool_schemas'),
            ]);
        $services->alias(FormFiller::class, 'form_bridge.form_filler');

        if (null !== ($fill['rate_limiter'] ?? null)) {
            if (!interface_exists(RateLimiterFactoryInterface::class)) {
                throw new LogicException('Limiting the "paste a message" page requires the RateLimiter component. Try running "composer require symfony/rate-limiter".');
            }
            $builder->getDefinition(FillController::class)->replaceArgument(7, new Reference('limiter.'.$fill['rate_limiter']));
        }
    }

    /**
     * @param list<string> $agents
     */
    private function registerAgentToolboxes(array $agents, ContainerConfigurator $container): void
    {
        if (!interface_exists(ToolboxInterface::class)) {
            throw new LogicException('Giving form tools to Symfony AI agents requires the Agent component. Try running "composer require symfony/ai-agent".');
        }

        foreach ($agents as $agent) {
            // Decorates the agent's own toolbox, so the agent keeps its other tools.
            $container->services()
                ->set('form_bridge.agent_toolbox.'.$agent, FormToolbox::class)
                ->decorate('ai.toolbox.'.$agent)
                ->args([
                    service('form_bridge.registry'),
                    service('form_bridge.runner'),
                    service('form_bridge.tool_schemas'),
                    service('form_bridge.draft_log'),
                    service('.inner'),
                ]);
        }
    }
}
