<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit;

use LogicException;
use Payxy\Auth\PublicKey;
use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use Payxy\PayxyConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayxyConfigTest extends TestCase
{
    private const TEST_KEY = 'pk_test_0123456789abcdef';
    private const LIVE_KEY = 'pk_live_0123456789abcdef';

    public function testTestKeyTargetsTheSandbox(): void
    {
        $config = new PayxyConfig(self::TEST_KEY);

        self::assertSame(Environment::Sandbox, $config->environment);
        self::assertSame('https://api.payxy.ng', $config->baseUrl);
        self::assertSame('https://checkout.payxy.ng/a%2Fb', $config->checkoutUrlFor('a/b'));
    }

    public function testLiveKeyTargetsProduction(): void
    {
        $config = new PayxyConfig(self::LIVE_KEY, 'sk_live_0123456789abcdef');

        self::assertSame(Environment::Production, $config->environment);
        self::assertSame('https://api.payxy.africa', $config->baseUrl);
        self::assertSame('https://checkout.payxy.africa/AC1', $config->checkoutUrlFor('AC1'));
        self::assertSame('production', $config->environment->browserSdkEnvironment());
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsKeysThatAreNotPublicKeys(string $key): void
    {
        $this->expectException(ConfigurationException::class);
        new PayxyConfig($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'test secret key' => ['sk_test_0123456789abcdef'];
        yield 'live secret key' => ['sk_live_0123456789abcdef'];
        yield 'no environment' => ['pk_0123456789abcdef'];
        yield 'header injection' => ["pk_test_0123456789\r\nX-Evil: 1"];
        yield 'whitespace' => ['pk_test_0123 456789'];
    }

    public function testRejectsWebhookSecretFromAnotherEnvironment(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('different environments');
        new PayxyConfig(self::TEST_KEY, 'sk_live_0123456789abcdef');
    }

    public function testRejectsAPublicKeyInTheWebhookSecretSlot(): void
    {
        $this->expectException(ConfigurationException::class);
        new PayxyConfig(self::TEST_KEY, self::TEST_KEY);
    }

    public function testRejectsAKeyPointedAtTheOtherEnvironmentsApi(): void
    {
        $this->expectException(ConfigurationException::class);
        new PayxyConfig(self::LIVE_KEY, baseUrl: 'https://api.payxy.ng');
    }

    #[DataProvider('allowedOverrides')]
    public function testBaseUrlCanBeOverriddenForProxiesAndMocks(string $url, string $expected): void
    {
        self::assertSame($expected, (new PayxyConfig(self::TEST_KEY, baseUrl: $url))->baseUrl);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function allowedOverrides(): iterable
    {
        yield ['https://payxy-proxy.internal.example/', 'https://payxy-proxy.internal.example'];
        yield ['http://localhost:5000', 'http://localhost:5000'];
        yield ['http://payxy.localhost', 'http://payxy.localhost'];
    }

    #[DataProvider('rejectedOverrides')]
    public function testRejectsInsecureOrAmbiguousBaseUrls(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        new PayxyConfig(self::TEST_KEY, baseUrl: $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedOverrides(): iterable
    {
        yield 'plain http' => ['http://api.payxy.ng'];
        yield 'relative' => ['/api'];
        yield 'credentials' => ['https://user:pass@api.payxy.ng'];
        yield 'query' => ['https://api.payxy.ng?x=1'];
        yield 'lookalike' => ['http://localhost.evil.com'];
    }

    public function testFromArrayReadsFrameworkSettings(): void
    {
        $config = PayxyConfig::fromArray([
            'public_key' => self::TEST_KEY,
            'webhook_secret' => 'sk_test_0123456789abcdef',
            'base_url' => '',
            'timeout' => '15',
            'max_retries' => '1',
        ]);

        self::assertSame('https://api.payxy.ng', $config->baseUrl);
        self::assertSame(15.0, $config->timeoutSeconds);
        self::assertSame([2000], $config->retryPolicy->delaysMs);
        self::assertNotNull($config->webhookSecret);
    }

    public function testFromArrayExplainsAMissingKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PAYXY_PUBLIC_KEY');
        PayxyConfig::fromArray([]);
    }

    public function testRejectsInconsistentTimeouts(): void
    {
        $this->expectException(ConfigurationException::class);
        new PayxyConfig(self::TEST_KEY, timeoutSeconds: 5, connectTimeoutSeconds: 10);
    }

    public function testKeysAreHiddenFromDebugOutputAndSerialization(): void
    {
        $config = new PayxyConfig(self::TEST_KEY, 'sk_test_fedcba9876543210');
        $dump = print_r($config, true);

        self::assertStringNotContainsString('0123456789abcdef', $dump);
        self::assertStringNotContainsString('fedcba9876543210', $dump);
        self::assertStringContainsString('pk_test_***', $dump);

        $this->expectException(LogicException::class);
        serialize(new PublicKey(self::TEST_KEY));
    }
}
