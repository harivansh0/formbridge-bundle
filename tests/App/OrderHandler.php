<?php

declare(strict_types=1);

namespace FormBridge\Tests\App;

use FormBridge\Attribute\AsFormTool;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsFormTool(
    name: 'place_order',
    form: OrderType::class,
    description: 'Order coffee beans.',
    title: 'Place an order',
    submitLabel: 'Order',
)]
final class OrderHandler
{
    public function __construct(
        private readonly Orders $orders,
    ) {
    }

    /**
     * @param array{product: string, quantity: int, email: string, gift: bool, delivery: array{street: ?string, city: string}} $order
     */
    public function __invoke(array $order): string
    {
        $left = $this->orders->stock($order['product']);
        if ($order['quantity'] > $left) {
            throw new ValidationFailedException($order, new ConstraintViolationList([new ConstraintViolation(\sprintf('Only %d left.', $left), null, [], $order, 'quantity', $order['quantity'])]));
        }

        $number = $this->orders->place($order['product'], $order['quantity'], $order['email']);

        return \sprintf('Order #%d: %d × %s to %s.', $number, $order['quantity'], $order['product'], $order['delivery']['city']);
    }
}
