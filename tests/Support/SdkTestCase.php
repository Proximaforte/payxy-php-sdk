<?php

declare(strict_types=1);

namespace Payxy\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Payxy\Http\RetryPolicy;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

abstract class SdkTestCase extends TestCase
{
    protected const BASE_URL = 'https://api.payxy.ng';
    protected const PUBLIC_KEY = 'pk_test_0123456789abcdef';
    protected const WEBHOOK_SECRET = 'sk_test_fedcba9876543210';

    protected FakeHttpClient $http;

    protected RecordingSleeper $sleeper;

    protected function payxy(ResponseInterface|ClientExceptionInterface ...$queue): Payxy
    {
        return $this->payxyWith(new RetryPolicy(), ...$queue);
    }

    protected function payxyWith(RetryPolicy $retryPolicy, ResponseInterface|ClientExceptionInterface ...$queue): Payxy
    {
        $this->http = new FakeHttpClient(...$queue);
        $this->sleeper = new RecordingSleeper();
        $factory = new Psr17Factory();

        return new Payxy(
            new PayxyConfig(self::PUBLIC_KEY, self::WEBHOOK_SECRET, retryPolicy: $retryPolicy),
            $this->http,
            $factory,
            $factory,
            sleeper: $this->sleeper,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     */
    protected static function json(int $status, array $body, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A successful Payxy envelope: {"responseCode":"00","responseMessage":...,"data":...}.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    protected static function ok(array $data, string $message = 'Request processed successfully', array $headers = []): ResponseInterface
    {
        return self::json(200, ['responseCode' => '00', 'responseMessage' => $message, 'data' => $data], $headers);
    }

    protected static function networkError(): ClientExceptionInterface
    {
        return new FakeNetworkException(new Request('POST', self::BASE_URL));
    }
}
