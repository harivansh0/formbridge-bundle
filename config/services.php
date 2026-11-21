<?php

declare(strict_types=1);

use FormBridge\Agent\DraftLog;
use FormBridge\Command\DebugFormToolsCommand;
use FormBridge\Controller\FillController;
use FormBridge\Controller\PreviewController;
use FormBridge\Controller\ReviewController;
use FormBridge\DraftStore;
use FormBridge\EventListener\FrameGuardListener;
use FormBridge\Form\FormSchemaFactory;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use FormBridge\Mcp\FormBridgeApp;
use FormBridge\Mcp\FormToolLoader;
use FormBridge\Mcp\FormToolResponder;
use FormBridge\Mcp\FreshStateListener;
use Mcp\Event\RequestEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Filled by FormToolPass.
    $services->set('form_bridge.registry', FormToolRegistry::class)
        ->args([[], null]);
    $services->alias(FormToolRegistry::class, 'form_bridge.registry');

    $services->set('form_bridge.schema_factory', FormSchemaFactory::class)
        ->args([
            service('validator'),
            tagged_iterator('form_bridge.field_describer'),
            tagged_iterator('form_bridge.constraint_describer'),
        ]);
    $services->alias(FormSchemaFactory::class, 'form_bridge.schema_factory');

    $services->set('form_bridge.runner', FormToolRunner::class)
        ->args([
            service('form.factory'),
            service('form.registry'),
            service('form_bridge.registry'),
            service('event_dispatcher'),
            service('logger'),
        ])
        ->tag('monolog.logger', ['channel' => 'formbridge']);
    $services->alias(FormToolRunner::class, 'form_bridge.runner');

    $services->set('form_bridge.tool_schemas', FormToolSchemas::class)
        ->args([
            service('form_bridge.registry'),
            service('form_bridge.runner'),
            service('form_bridge.schema_factory'),
            param('kernel.debug'),
            service('logger'),
        ])
        ->tag('monolog.logger', ['channel' => 'formbridge']);
    $services->alias(FormToolSchemas::class, 'form_bridge.tool_schemas');

    $services->set('form_bridge.cache')
        ->parent('cache.app')
        ->tag('cache.pool');

    $services->set('form_bridge.draft_store', DraftStore::class)
        ->args([service('form_bridge.cache'), service('lock.factory'), param('form_bridge.drafts.lifetime')]);
    $services->alias(DraftStore::class, 'form_bridge.draft_store');

    $services->set('form_bridge.draft_log', DraftLog::class)
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(DraftLog::class, 'form_bridge.draft_log');

    $services->set('form_bridge.mcp.responder', FormToolResponder::class)
        ->args([service('twig'), service('form_bridge.draft_store'), service('router'), param('form_bridge.review_page')]);
    $services->alias(FormToolResponder::class, 'form_bridge.mcp.responder');

    $services->set('form_bridge.mcp.loader', FormToolLoader::class)
        ->args([
            service('form_bridge.tool_schemas'),
            service('form_bridge.runner'),
            service('form_bridge.mcp.responder'),
            service('form_bridge.draft_store'),
        ])
        ->tag('mcp.loader');

    $services->set('form_bridge.mcp.app', FormBridgeApp::class)
        ->tag('mcp.app');

    $services->set('form_bridge.mcp.fresh_state_listener', FreshStateListener::class)
        ->args([service('services_resetter')])
        ->tag('kernel.event_listener', ['event' => ConsoleCommandEvent::class, 'method' => 'onConsoleCommand'])
        ->tag('kernel.event_listener', ['event' => RequestEvent::class, 'method' => 'onMcpRequest']);

    $services->set('form_bridge.command.debug_form_tools', DebugFormToolsCommand::class)
        ->args([service('form_bridge.registry'), service('form_bridge.tool_schemas')])
        ->tag('console.command');

    $services->set('form_bridge.frame_guard_listener', FrameGuardListener::class)
        ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse']);

    // Controllers are looked up by class name, which the routes carry.
    $services->set(ReviewController::class)
        ->public()
        ->args([
            service('form_bridge.draft_store'),
            service('form_bridge.runner'),
            service('twig'),
            service('security.csrf.token_manager'),
            param('form_bridge.review_page'),
        ]);

    $services->set(FillController::class)
        ->public()
        ->args([
            service('form_bridge.form_filler')->nullOnInvalid(),
            service('form_bridge.registry'),
            service('form_bridge.draft_store'),
            service('form.factory'),
            service('router'),
            service('twig'),
            param('kernel.debug'),
            null,
            service('logger'),
        ])
        ->tag('monolog.logger', ['channel' => 'formbridge']);

    $services->set(PreviewController::class)
        ->public()
        ->args([service('twig'), param('kernel.debug')]);
};
