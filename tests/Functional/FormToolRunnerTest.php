<?php

declare(strict_types=1);

namespace FormBridge\Tests\Functional;

use FormBridge\Event\FormDraftedEvent;
use FormBridge\Event\FormSavedEvent;
use FormBridge\Event\FormSubmitEvent;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use FormBridge\Tests\App\Orders;
use FormBridge\Tests\FunctionalTestCase;

final class FormToolRunnerTest extends FunctionalTestCase
{
    private FormToolRunner $runner;
    private Orders $orders;
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->runner = $container->get(FormToolRunner::class);
        $this->orders = $container->get(Orders::class);

        foreach ([FormDraftedEvent::class, FormSavedEvent::class] as $event) {
            $container->get('event_dispatcher')->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testTheSchemaIsReadFromTheForm(): void
    {
        $container = self::getContainer();
        $schema = $container->get(FormToolSchemas::class)->of($container->get(FormToolRegistry::class)->get('place_order'));
        $properties = $schema['properties'];

        self::assertSame(['product', 'quantity', 'email', 'gift', 'delivery'], array_keys($properties));
        self::assertFalse($schema['additionalProperties']);
        self::assertArrayNotHasKey('required', $schema);

        self::assertSame('string', $properties['product']['type']);
        self::assertArrayNotHasKey('enum', $properties['product']);
        self::assertStringContainsString('espresso = "Espresso beans (3 left)"', $properties['product']['description']);

        self::assertSame('integer', $properties['quantity']['type']);
        self::assertSame('Bags of 250 g. Required. Between 1 and 5.', $properties['quantity']['description']);
        self::assertArrayNotHasKey('maximum', $properties['quantity']);

        self::assertSame('boolean', $properties['gift']['type']);
        self::assertSame(['street', 'city'], array_keys($properties['delivery']['properties']));
        self::assertFalse($properties['delivery']['additionalProperties']);
    }

    public function testDraftingValidatesAndNeverSaves(): void
    {
        $outcome = $this->runner->draft('place_order', ['quantity' => 9, 'email' => 'not-an-email']);

        self::assertFalse($outcome->valid);
        self::assertFalse($outcome->saved);
        self::assertEqualsCanonicalizing(['product', 'quantity', 'email', 'delivery.city'], array_column($outcome->errors, 'field'));
        self::assertSame([], $this->orders->all());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(FormDraftedEvent::class, $this->events[0]);
    }

    public function testSubmittingRunsTheHandlerAndAnnouncesIt(): void
    {
        $outcome = $this->runner->submit('place_order', ['quantity' => '2'] + self::ORDER);

        self::assertTrue($outcome->saved);
        self::assertSame('Order #1: 2 × espresso to Lyon.', $outcome->result);
        self::assertCount(1, $this->orders->all());
        self::assertSame(1, $this->orders->stock('espresso'));

        self::assertCount(1, $this->events);
        self::assertInstanceOf(FormSavedEvent::class, $this->events[0]);
        self::assertSame('espresso', $this->events[0]->data['product']);
        self::assertSame($outcome->result, $this->events[0]->result);
    }

    public function testTheHandlerHasTheLastWordAndItsRefusalLandsOnTheField(): void
    {
        Orders::reset(espresso: 1);

        $outcome = $this->runner->submit('place_order', self::ORDER);

        self::assertFalse($outcome->saved);
        self::assertSame([['field' => 'quantity', 'message' => 'Only 1 left.']], $outcome->errors);
        self::assertSame([], $this->orders->all());
        self::assertSame([], $this->events);
    }

    public function testAListenerCanRefuseAValidFormBeforeTheHandlerRuns(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(FormSubmitEvent::class, static function (FormSubmitEvent $event): void {
            $event->refuse('Sign in to order.');
        });

        $outcome = $this->runner->submit('place_order', self::ORDER);

        self::assertFalse($outcome->saved);
        self::assertSame([['field' => '', 'message' => 'Sign in to order.']], $outcome->errors);
        self::assertSame([], $this->orders->all());
        self::assertSame([], $this->events);
    }

    public function testShowingAFormAgainAnnouncesNothing(): void
    {
        self::assertTrue($this->runner->review('place_order', self::ORDER)->valid);
        self::assertSame([], $this->events);
    }
}
