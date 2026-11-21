<?php

declare(strict_types=1);

namespace FormBridge\Tests\App;

/**
 * Stock and orders, kept in a file so that they survive the kernel reboots between test requests.
 */
final class Orders
{
    private const PRODUCTS = ['espresso' => 'Espresso beans', 'filter' => 'Filter roast'];

    public static function reset(int $espresso = 3, int $filter = 10): void
    {
        self::write(['stock' => ['espresso' => $espresso, 'filter' => $filter], 'orders' => []]);
    }

    /**
     * @return array<string, string> label => value, as a choice field takes them
     */
    public function choices(): array
    {
        $choices = [];
        foreach (self::PRODUCTS as $id => $name) {
            $choices[\sprintf('%s (%d left)', $name, $this->stock($id))] = $id;
        }

        return $choices;
    }

    public function stock(string $product): int
    {
        return self::read()['stock'][$product] ?? 0;
    }

    public function place(string $product, int $quantity, string $email): int
    {
        $state = self::read();
        $state['stock'][$product] -= $quantity;
        $state['orders'][] = ['product' => $product, 'quantity' => $quantity, 'email' => $email];
        self::write($state);

        return \count($state['orders']);
    }

    /**
     * @return list<array{product: string, quantity: int, email: string}>
     */
    public function all(): array
    {
        return self::read()['orders'];
    }

    /**
     * @return array{stock: array<string, int>, orders: list<array{product: string, quantity: int, email: string}>}
     */
    private static function read(): array
    {
        return json_decode((string) file_get_contents(self::file()), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function write(array $state): void
    {
        if (!is_dir(\dirname(self::file()))) {
            mkdir(\dirname(self::file()), 0o777, true);
        }
        file_put_contents(self::file(), json_encode($state, \JSON_THROW_ON_ERROR));
    }

    private static function file(): string
    {
        return sys_get_temp_dir().'/formbridge-tests/orders.json';
    }
}
