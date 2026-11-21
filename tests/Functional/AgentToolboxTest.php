<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Agent\DraftLog;
use FormBridge\Agent\FormToolbox;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use FormBridge\Tests\App\Orders;
use FormBridge\Tests\FunctionalTestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * A Symfony AI agent gets the forms as tools: it can fill them in, the form answers, nothing is saved.
 */
final class AgentToolboxTest extends FunctionalTestCase
{
    private DraftLog $drafts;
    private FormToolbox $toolbox;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->drafts = new DraftLog();
        $this->toolbox = new FormToolbox(
            $container->get(FormToolRegistry::class),
            $container->get(FormToolRunner::class),
            $container->get(FormToolSchemas::class),
            $this->drafts,
        );
    }

    public function testEveryFormIsAReadOnlyToolWithParametersReadFromTheForm(): void
    {
        $tools = $this->toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertSame('place_order', $tools[0]->getName());
        self::assertTrue($tools[0]->isReadOnly());
        self::assertStringContainsString('espresso = "Espresso beans (3 left)"', $tools[0]->getParameters()['properties']['product']['description']);
    }

    public function testThereIsNoToolForSaving(): void
    {
        $this->expectException(ToolNotFoundException::class);

        $this->toolbox->execute(new ToolCall('1', 'place_order_submit', self::ORDER));
    }

    public function testCallingAToolFillsTheFormInAndReturnsTheFormsOwnAnswer(): void
    {
        $result = $this->toolbox->execute(new ToolCall('1', 'place_order', ['quantity' => 9] + self::ORDER));

        self::assertStringContainsString('quantity: This value should be between 1 and 5.', $result->getResult());
        self::assertFalse($this->drafts->last()?->outcome->valid);
        self::assertSame(['quantity' => 9] + self::ORDER, $this->drafts->last()->arguments);
        self::assertSame([], self::getContainer()->get(Orders::class)->all());
    }
}
