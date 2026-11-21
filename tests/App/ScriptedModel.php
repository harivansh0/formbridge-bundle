<?php

declare(strict_types=1);

namespace FormBridge\Tests\App;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * The model behind the "paste a message" page in tests: it answers what a test tells it to.
 */
final class ScriptedModel
{
    public static string|\Throwable $answer = '{}';

    /** @var list<array<string, mixed>> the options of every call */
    public static array $calls = [];

    public static function platform(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function ($model, $payload, array $options): string {
            self::$calls[] = $options;
            if (self::$answer instanceof \Throwable) {
                throw self::$answer;
            }

            return self::$answer;
        });
    }
}
