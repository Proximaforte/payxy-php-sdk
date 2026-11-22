<?php

declare(strict_types=1);

namespace Payxy\Tests\Contract;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Http\RetryPolicy;
use Payxy\Merchant\AccountType;
use Payxy\Merchant\Provider;
use Payxy\Merchant\Request\CreatePayoutRequest;
use Payxy\Merchant\Request\CreateVirtualAccountRequest;
use Payxy\Merchant\Request\SimulateCreditRequest;
use Payxy\Merchant\Request\VirtualAccountCustomer;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Support\Amount;
use Payxy\Tests\Support\FakeHttpClient;
use Payxy\Tests\Support\RecordingSleeper;
use Payxy\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Drives the money-movement API and the typed webhook events from the language-neutral
 * fixtures in /contract, which every Payxy SDK reads.
 */
final class MoneyContractTest extends TestCase
{
    private const OAUTH = '/api/v1/oauth/token';

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../../contract/' . $name), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? self::stringKeys($decoded) : [];
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authScenarios(): iterable
    {
        foreach (self::list(self::fixture('machine-auth.json')['scenarios'] ?? []) as $scenario) {
            yield self::text($scenario['name'] ?? '') => [$scenario];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function moneyCases(): iterable
    {
        foreach (self::list(self::fixture('money-calls.json')['cases'] ?? []) as $case) {
            yield self::text($case['name'] ?? '') => [$case];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidCases(): iterable
    {
        foreach (self::list(self::fixture('money-calls.json')['invalid'] ?? []) as $case) {
            yield self::text($case['name'] ?? '') => [$case];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function eventCases(): iterable
    {
        foreach (self::list(self::fixture('webhook-events.json')['events'] ?? []) as $case) {
            yield self::text($case['name'] ?? '') => [$case];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function acceptedEvents(): iterable
    {
        foreach (self::list(self::verification()['accepted'] ?? []) as $case) {
            yield self::text($case['name'] ?? '') => [$case];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedEvents(): iterable
    {
        foreach (self::list(self::verification()['rejected'] ?? []) as $case) {
            yield self::text($case['name'] ?? '') => [$case];
        }
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('authScenarios')]
    public function testMachineAuthentication(array $scenario): void
    {
        $fixture = self::fixture('machine-auth.json');
        $secretKey = \array_key_exists('secretKey', $scenario) ? $scenario['secretKey'] : $fixture['secretKey'];
        $now = 1_000_000_000;
        $http = $this->scripted(self::list($scenario['script'] ?? []));
        $payxy = $this->payxy(self::text($fixture['publicKey'] ?? ''), \is_string($secretKey) ? $secretKey : null, $http, 0, static function () use (&$now): int {
            return $now;
        });

        $caught = null;
        foreach (self::list($scenario['steps'] ?? []) as $step) {
            if (isset($step['advanceSeconds'])) {
                $now += self::int($step['advanceSeconds']);

                continue;
            }
            try {
                $this->call($payxy, self::text($step['call'] ?? ''), self::map($step['args'] ?? []), null);
            } catch (Throwable $e) {
                $caught = $e;

                break;
            }
        }

        $this->assertError($scenario['error'] ?? null, $caught);
        $this->assertRequests($http, self::list($scenario['requests'] ?? []), false);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('moneyCases')]
    public function testMoneyCall(array $case): void
    {
        [$payxy, $http] = $this->moneyClient($case, self::list($case['script'] ?? []));

        $result = null;
        $caught = null;
        try {
            $key = $case['idempotencyKey'] ?? null;
            $result = $this->call($payxy, self::text($case['call'] ?? ''), self::map($case['args'] ?? []), \is_string($key) ? $key : null);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertError($case['error'] ?? null, $caught);
        $this->assertRequests($http, self::list($case['requests'] ?? []), true);
        foreach (self::map($case['result'] ?? []) as $path => $expected) {
            self::assertEquals($expected, self::resolve($result, $path), $path);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('invalidCases')]
    public function testInvalidInputIsRejectedLocally(array $case): void
    {
        [$payxy, $http] = $this->moneyClient($case, []);

        $key = \array_key_exists('idempotencyKey', $case) ? self::text($case['idempotencyKey']) : 'valid-key-1';
        $caught = null;
        try {
            $this->call($payxy, self::text($case['call'] ?? ''), self::map($case['args'] ?? []), $key);
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertSame('InvalidArgument', self::errorKind($caught));
        self::assertStringContainsString('"' . self::text($case['field'] ?? '') . '"', $caught?->getMessage() ?? '');
        self::assertSame([], $http->requests);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('eventCases')]
    public function testWebhookEvent(array $case): void
    {
        $event = $this->receive($case['payload'] ?? []);

        foreach (self::map($case['expect'] ?? []) as $path => $expected) {
            self::assertEquals($expected, self::resolve($event, $path), $path);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('acceptedEvents')]
    public function testVerificationAccepts(array $case): void
    {
        $this->receive($case['payload'] ?? []);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('rejectedEvents')]
    public function testVerificationRejects(array $case): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage(self::text($case['message'] ?? ''));

        $this->receive($case['payload'] ?? []);
    }

    /**
     * A client for one money-calls case, with the token exchange queued first.
     *
     * @param array<string, mixed> $case
     * @param list<array<string, mixed>> $script
     *
     * @return array{Payxy, FakeHttpClient}
     */
    private function moneyClient(array $case, array $script): array
    {
        $fixture = self::fixture('money-calls.json');
        $keys = self::map($fixture[($case['keys'] ?? 'test') === 'live' ? 'liveKeys' : 'testKeys'] ?? []);
        $http = $this->scripted([self::map($fixture['token'] ?? []), ...$script]);

        return [
            $this->payxy(self::text($keys['publicKey'] ?? ''), self::text($keys['secretKey'] ?? ''), $http, self::int($case['maxRetries'] ?? 0)),
            $http,
        ];
    }

    private function receive(mixed $payload): \Payxy\Webhook\WebhookEvent
    {
        $fixture = self::fixture('webhook-events.json');
        $secret = self::text($fixture['secret'] ?? '');
        $now = self::int($fixture['now'] ?? 0);
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $signature = hash_hmac('sha512', $now . '.' . $body, $secret);

        return (new WebhookVerifier($secret, clock: static fn(): int => $now))->constructEvent($body, $signature, (string) $now);
    }

    /**
     * @param (Closure(): int)|null $clock
     */
    private function payxy(string $publicKey, ?string $secretKey, FakeHttpClient $http, int $maxRetries, ?Closure $clock = null): Payxy
    {
        $factory = new Psr17Factory();

        return new Payxy(
            new PayxyConfig($publicKey, $secretKey, retryPolicy: RetryPolicy::withRetries($maxRetries)),
            $http,
            $factory,
            $factory,
            sleeper: new RecordingSleeper(),
            clock: $clock ?? static fn(): int => 1_000_000_000,
        );
    }

    /**
     * @param list<array<string, mixed>> $script
     */
    private function scripted(array $script): FakeHttpClient
    {
        $responses = [];
        foreach ($script as $step) {
            $body = $step['body'] ?? [];
            $responses[] = new Response(
                self::int($step['status'] ?? 200),
                ['Content-Type' => 'application/json'],
                \is_string($body) ? $body : ($body === [] ? '{}' : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)),
            );
        }

        return new FakeHttpClient(...$responses);
    }

    /**
     * @param array<string, mixed> $a
     */
    private function call(Payxy $payxy, string $call, array $a, ?string $key): mixed
    {
        $key ??= '';

        return match ($call) {
            'payments.verify' => $payxy->payments()->verify('ORD-123456'),
            'wallet.balance' => $payxy->wallet()->balance(),
            'providers.list' => $payxy->providers()->list(),
            'virtualAccounts.create' => $payxy->virtualAccounts()->create($this->virtualAccount($a), $key),
            'payouts.create' => $payxy->payouts()->create($this->payout($a), $key),
            'payouts.get' => $payxy->payouts()->get(self::text($a['id'] ?? '')),
            'payouts.tsq' => $payxy->payouts()->tsq(self::text($a['reference'] ?? '')),
            'simulator.creditVirtualAccount' => $payxy->simulator()->creditVirtualAccount($this->simulatedCredit($a), $key),
            'webhookEvents.list' => $payxy->webhookEvents()->list(array_map(self::text(...), self::map($a['query'] ?? []))),
            'webhookEvents.resend' => $payxy->webhookEvents()->resend(self::text($a['eventId'] ?? '')),
            default => self::fail('Unknown call ' . $call),
        };
    }

    /**
     * @param array<string, mixed> $a
     */
    private function virtualAccount(array $a): CreateVirtualAccountRequest
    {
        $c = self::map($a['customer'] ?? []);
        $expiresAt = $a['expiresAt'] ?? null;
        $expected = $a['expectedAmount'] ?? null;
        $currency = $a['currency'] ?? null;

        return new CreateVirtualAccountRequest(
            provider: Provider::from(self::text($a['provider'] ?? '')),
            accountType: AccountType::from(self::text($a['accountType'] ?? '')),
            customer: new VirtualAccountCustomer(
                reference: self::text($c['reference'] ?? ''),
                firstName: self::text($c['firstName'] ?? ''),
                lastName: self::text($c['lastName'] ?? ''),
                email: self::optional($c['email'] ?? null),
                phone: self::optional($c['phone'] ?? null),
                bvn: self::optional($c['bvn'] ?? null),
                dateOfBirth: self::optional($c['dateOfBirth'] ?? null),
            ),
            currency: \is_string($currency) ? $currency : null,
            expiresAt: \is_string($expiresAt) ? $expiresAt : null,
            expectedAmount: \is_string($expected) ? $expected : null,
            metadata: array_map(self::text(...), self::map($a['metadata'] ?? [])),
        );
    }

    /**
     * @param array<string, mixed> $a
     */
    private function payout(array $a): CreatePayoutRequest
    {
        return new CreatePayoutRequest(
            accountNumber: self::text($a['accountNumber'] ?? ''),
            accountName: self::text($a['accountName'] ?? ''),
            bankCode: self::text($a['bankCode'] ?? ''),
            amount: self::text($a['amount'] ?? ''),
            bankName: self::optional($a['bankName'] ?? null),
            narration: self::optional($a['narration'] ?? null),
            transactionPin: self::optional($a['transactionPin'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $a
     */
    private function simulatedCredit(array $a): SimulateCreditRequest
    {
        return new SimulateCreditRequest(
            accountNumber: self::text($a['accountNumber'] ?? ''),
            amount: self::text($a['amount'] ?? ''),
            senderAccountNumber: self::optional($a['senderAccountNumber'] ?? null),
            senderName: self::optional($a['senderName'] ?? null),
        );
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    private function assertRequests(FakeHttpClient $http, array $expected, bool $withoutTokenExchange): void
    {
        $actual = array_values(array_filter(
            $http->requests,
            static fn($r): bool => !$withoutTokenExchange || $r->getUri()->getPath() !== self::OAUTH,
        ));
        self::assertCount(\count($expected), $actual);

        foreach ($expected as $i => $e) {
            $request = $actual[$i];
            $query = $request->getUri()->getQuery();
            if (isset($e['method'])) {
                self::assertSame($e['method'], $request->getMethod());
            }
            self::assertSame($e['path'] ?? null, $request->getUri()->getPath() . ($query === '' ? '' : '?' . $query));
            foreach (self::map($e['headers'] ?? []) as $name => $value) {
                self::assertSame($value, $request->hasHeader($name) ? $request->getHeaderLine($name) : null, 'header ' . $name);
            }
            if (isset($e['body'])) {
                self::assertEquals($e['body'], json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR));
            }
        }
    }

    private function assertError(mixed $expected, ?Throwable $caught): void
    {
        if (\is_string($expected)) {
            self::assertSame($expected, self::errorKind($caught), (string) $caught);

            return;
        }
        self::assertNull($caught, (string) $caught);
    }

    private static function errorKind(?Throwable $e): ?string
    {
        return $e === null ? null : (string) preg_replace('/Exception$/', '', (new ReflectionClass($e))->getShortName());
    }

    /**
     * Follows "a.b[0].c" through accessors and arrays; "#count" gives a list's length.
     */
    private static function resolve(mixed $root, string $path): mixed
    {
        $current = $root;
        foreach (explode('.', $path) as $raw) {
            $count = str_ends_with($raw, '#count');
            $part = $count ? substr($raw, 0, -\strlen('#count')) : $raw;
            preg_match('/^([^\[]+)(?:\[(\d+)\])?$/', $part, $m);
            if ($current === null || !isset($m[1])) {
                return null;
            }
            $current = self::member($current, $m[1]);
            if (isset($m[2]) && \is_array($current)) {
                $current = $current[(int) $m[2]] ?? null;
            }
            if ($count) {
                return \is_array($current) ? \count($current) : null;
            }
        }

        return self::normalize($current);
    }

    private static function member(mixed $holder, string $name): mixed
    {
        if (\is_array($holder)) {
            return $holder[$name] ?? null;
        }
        if (!\is_object($holder)) {
            return null;
        }
        foreach ([$name, 'get' . ucfirst($name), 'is' . ucfirst($name)] as $method) {
            if (method_exists($holder, $method)) {
                return (new ReflectionMethod($holder, $method))->invoke($holder);
            }
        }

        return self::fail(\sprintf('%s has no accessor for "%s".', $holder::class, $name));
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Amount => $value->toDecimalString(),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function verification(): array
    {
        return self::map(self::fixture('webhook-events.json')['verification'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            $out[] = \is_array($item) ? self::stringKeys($item) : [];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? self::stringKeys($value) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function optional(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
