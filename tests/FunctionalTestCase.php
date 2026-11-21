<?php

declare(strict_types=1);

namespace FormBridge\Tests;

use FormBridge\Tests\App\Orders;
use FormBridge\Tests\App\ScriptedModel;
use FormBridge\Tests\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class FunctionalTestCase extends WebTestCase
{
    protected const ORDER = ['product' => 'espresso', 'quantity' => 2, 'email' => 'mia@example.com', 'delivery' => ['city' => 'Lyon']];

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        Orders::reset();
        ScriptedModel::$answer = '{}';
        ScriptedModel::$calls = [];
        $this->client = static::createClient();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
