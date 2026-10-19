<?php

declare(strict_types=1);

namespace Payxy\Demo;

use Payxy\Support\Amount;

/**
 * Stand-in for the merchant's orders table: a JSON file in the system temp directory.
 */
final class OrderStore
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/payxy-demo-orders.json';
    }

    public function save(Order $order): void
    {
        $all = $this->all();
        $all[$order->transactionId] = ['amount' => $order->amount->toMinorUnits(), 'email' => $order->email, 'status' => $order->status];
        file_put_contents($this->path, json_encode($all, \JSON_PRETTY_PRINT), \LOCK_EX);
    }

    public function find(string $transactionId): ?Order
    {
        $row = $this->all()[$transactionId] ?? null;

        return \is_array($row)
            ? new Order($transactionId, Amount::fromMinorUnits((int) $row['amount']), (string) $row['email'], (string) $row['status'])
            : null;
    }

    /**
     * @return array<string, array{amount: int, email: string, status: string}>
     */
    private function all(): array
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $data = $raw === false ? [] : json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }
}

final class Order
{
    public function __construct(
        public readonly string $transactionId,
        public readonly Amount $amount,
        public readonly string $email,
        public string $status = 'awaiting payment',
    ) {}
}
