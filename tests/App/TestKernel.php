<?php

declare(strict_types=1);

namespace FormBridge\Tests\App;

use FormBridge\FormBridgeBundle;
use Psr\Log\NullLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A host application of the smallest useful size: one form, one handler, no database.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new McpBundle();
        yield new FormBridgeBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/formbridge-tests/cache/'.$this->environment.($this->debug ? '' : '-nodebug');
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/formbridge-tests/log';
    }

    public function getShareDir(): ?string
    {
        return sys_get_temp_dir().'/formbridge-tests/share';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'not-a-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'validation' => ['email_validation_mode' => 'html5'],
            'lock' => 'flock',
            'default_locale' => 'en',
            'enabled_locales' => ['en', 'de', 'fr'],
            'set_locale_from_accept_language' => true,
            'translator' => ['enabled' => true],
            'rate_limiter' => [
                'messages' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute', 'storage_service' => 'test.limiter_storage'],
            ],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('mcp', [
            'servers' => [
                'default' => [
                    'name' => 'orders',
                    'description' => 'A coffee shop taking orders.',
                    'registry' => '*',
                    'transports' => ['http' => true, 'stdio' => false],
                    'http' => ['path' => '/mcp'],
                ],
            ],
        ]);
        $container->extension('form_bridge', [
            'fill' => ['platform' => 'test.platform', 'model' => 'test-model', 'rate_limiter' => 'messages'],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(Orders::class)->public();
        $services->set(OrderType::class);
        $services->set(OrderHandler::class)->public();
        $services->set('test.platform', PlatformInterface::class)->factory([ScriptedModel::class, 'platform']);
        $services->set('test.limiter_storage', InMemoryStorage::class);
        // Refused requests are expected in tests; keep them out of the test output.
        $services->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@FormBridgeBundle/config/routes.php');
        $routes->import('.', 'mcp');
    }
}
