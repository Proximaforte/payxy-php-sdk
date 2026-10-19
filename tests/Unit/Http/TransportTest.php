<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payxy\Exception\ApiException;
use Payxy\Exception\AuthenticationException;
use Payxy\Exception\ConflictException;
use Payxy\Exception\InvalidResponseException;
use Payxy\Exception\NotFoundException;
use Payxy\Exception\PayxyException;
use Payxy\Exception\PermissionDeniedException;
use Payxy\Exception\RateLimitException;
use Payxy\Exception\RequestFailedException;
use Payxy\Exception\ServerException;
use Payxy\Exception\TransportException;
use Payxy\Exception\ValidationException;
use Payxy\Http\RetryPolicy;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Support\Amount;
use Payxy\Tests\Support\FakeHttpClient;
use Payxy\Tests\Support\RecordingLogger;
use Payxy\Tests\Support\RecordingSleeper;
use Payxy\Tests\Support\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends SdkTestCase
{
    public function testSendsThePublicKeyAsBearerToTheKeysEnvironment(): void
    {
        $payxy = $this->payxy(self::ok(['status' => 'Successful']));

        $payxy->payments()->verify('ORDER-1');

        $request = $this->http->lastRequest();
        self::assertSame('https://api.payxy.ng/api/Payments/VerifyPayment', (string) $request->getUri());
        self::assertSame('Bearer ' . self::PUBLIC_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testVerifyFollowsTheBoundedRetrySchedule(): void
    {
        $payxy = $this->payxy(self::json(503, []), self::json(502, []), self::ok(['status' => 'Successful']));

        self::assertSame('Successful', $payxy->payments()->verify('ORDER-1')->getStatus());
        self::assertCount(3, $this->http->requests);
        self::assertSame([2000, 5000], $this->sleeper->sleeps);
    }

    public function testVerifyNeverPollsIndefinitely(): void
    {
        $payxy = $this->payxy(self::json(503, []), self::json(503, []), self::json(503, []), self::json(503, []));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected ServerException');
        } catch (ServerException) {
            self::assertCount(4, $this->http->requests, 'attempt 1 + three scheduled retries');
            self::assertSame([2000, 5000, 10000], $this->sleeper->sleeps);
        }
    }

    public function testVerifyIsRetriedAfterNetworkError(): void
    {
        $payxy = $this->payxy(self::networkError(), self::ok(['status' => 'Successful']));

        self::assertSame('Successful', $payxy->payments()->verify('ORDER-1')->getStatus());
        self::assertCount(2, $this->http->requests);
    }

    public function testRetryAfterLengthensButNeverShortensTheSchedule(): void
    {
        $payxy = $this->payxy(self::json(429, [], ['Retry-After' => '7']), self::json(429, [], ['Retry-After' => '1']), self::ok([]));

        $payxy->payments()->verify('ORDER-1');

        self::assertSame([7000, 5000], $this->sleeper->sleeps);
    }

    public function testRetriesStopAtTheConfiguredLimit(): void
    {
        $payxy = $this->payxyWith(RetryPolicy::withRetries(1), self::json(503, []), self::json(503, []));

        $this->expectException(ServerException::class);
        try {
            $payxy->payments()->verify('ORDER-1');
        } finally {
            self::assertCount(2, $this->http->requests);
        }
    }

    /**
     * @param ResponseInterface|ClientExceptionInterface $failure
     */
    #[DataProvider('ambiguousInitializeFailures')]
    public function testInitializeIsNeverRetriedAutomatically(ResponseInterface|ClientExceptionInterface $failure): void
    {
        $payxy = $this->payxy($failure, self::ok(['accessCode' => 'AC1']));

        try {
            $payxy->payments()->initialize(new InitializePaymentRequest('a@b.co', Amount::of(100), 'ORDER-0001'));
            self::fail('Expected an exception');
        } catch (PayxyException) {
            self::assertCount(1, $this->http->requests);
            self::assertSame([], $this->sleeper->sleeps);
        }
    }

    /**
     * @return iterable<string, array{ResponseInterface|ClientExceptionInterface}>
     */
    public static function ambiguousInitializeFailures(): iterable
    {
        yield '429' => [self::json(429, [], ['Retry-After' => '1'])];
        yield '503' => [self::json(503, [])];
        yield 'timeout' => [self::networkError()];
    }

    public function testInitializeTimeoutIsAnUnknownOutcome(): void
    {
        $payxy = $this->payxy(self::networkError());

        try {
            $payxy->payments()->initialize(new InitializePaymentRequest('a@b.co', Amount::of(100), 'ORDER-0001'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isOutcomeUnknown());
            self::assertStringContainsString('verify the payment', $e->getMessage());
        }
    }

    public function testCredentialsNeverReachTheLogs(): void
    {
        $logger = new RecordingLogger();
        $factory = new Psr17Factory();
        $this->http = new FakeHttpClient(self::json(401, [], []), self::ok(['isSuccessful' => true]));
        $payxy = new Payxy(new PayxyConfig(self::PUBLIC_KEY), $this->http, $factory, $factory, $logger, new RecordingSleeper());

        try {
            $payxy->payments()->verify('ORDER-1');
        } catch (AuthenticationException) {
        }
        $payxy->payments()->getPaymentInfo('secret-access-code');

        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertNotSame('[]', $logged);
        self::assertStringNotContainsString(self::PUBLIC_KEY, $logged);
        self::assertStringNotContainsString('Authorization', $logged);
        self::assertStringNotContainsString('Bearer', $logged);
        self::assertStringNotContainsString('secret-access-code', $logged, 'path values are logged as templates');
    }

    public function testPlainTextRejectionIsSurfacedWithAnAuthHint(): void
    {
        // Exactly what api.payxy.ng returns for an unknown key.
        $payxy = $this->payxy(new Response(401, ['Content-Type' => 'application/json'], '"Invalid merchant key"'));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('HTTP 401: Invalid merchant key. Check', $e->getMessage());
            self::assertStringContainsString('allowlisted', $e->getMessage());
            self::assertCount(1, $this->http->requests, '401 is never retried');
        }
    }

    public function testNonSuccessResponseCodeOnHttp200IsAnError(): void
    {
        $payxy = $this->payxy(self::json(200, ['responseCode' => '03', 'responseMessage' => 'No merchant found', 'data' => null]));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected RequestFailedException');
        } catch (RequestFailedException $e) {
            self::assertSame('03', $e->getResponseCode());
            self::assertSame(200, $e->getStatusCode());
            self::assertStringContainsString('No merchant found', $e->getMessage());
        }
    }

    public function testResponseCodeIsExposedOnHttpErrors(): void
    {
        $payxy = $this->payxyWith(RetryPolicy::none(), self::json(404, ['responseCode' => '03', 'responseMessage' => 'Not found']));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame('03', $e->getResponseCode());
            self::assertStringContainsString('(responseCode 03): Not found', $e->getMessage());
        }
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('statusMapping')]
    public function testErrorStatusesMapToSpecificExceptions(int $status, string $expected): void
    {
        $payxy = $this->payxyWith(RetryPolicy::none(), self::json($status, []));

        $this->expectException($expected);
        $payxy->payments()->verify('ORDER-1');
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statusMapping(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, PermissionDeniedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '422' => [422, ValidationException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, ServerException::class];
        yield '418' => [418, ApiException::class];
    }

    public function testValidationProblemDetailsAreExposed(): void
    {
        $payxy = $this->payxy(self::json(400, [
            'title' => 'One or more validation errors occurred.',
            'traceId' => '00-abc-01',
            'errors' => ['Amount' => ['The Amount field is required.'], 'Email' => 'Invalid'],
        ]));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['Amount' => ['The Amount field is required.'], 'Email' => ['Invalid']], $e->getErrors());
            self::assertSame('00-abc-01', $e->getRequestId());
            self::assertStringContainsString('validation errors', $e->getMessage());
        }
    }

    public function testHtmlErrorBodyBecomesAShortPlainExcerpt(): void
    {
        $payxy = $this->payxyWith(RetryPolicy::none(), new Response(502, [], '<html><body><h1>Bad   gateway</h1></body></html>'));

        try {
            $payxy->payments()->verify('ORDER-1');
            self::fail('Expected ServerException');
        } catch (ServerException $e) {
            self::assertSame([], $e->getResponseBody());
            self::assertStringEndsWith(': Bad gateway.', $e->getMessage());
        }
    }

    public function testNonJsonSuccessBodyIsRejected(): void
    {
        $payxy = $this->payxy(new Response(200, [], 'OK'));

        $this->expectException(InvalidResponseException::class);
        $payxy->payments()->verify('ORDER-1');
    }

    public function testOversizedResponseIsRejected(): void
    {
        $payxy = $this->payxy(new Response(200, [], '{"x":"' . str_repeat('a', 2_100_000) . '"}'));

        $this->expectException(InvalidResponseException::class);
        $payxy->payments()->verify('ORDER-1');
    }

    public function testRequestIdHeaderIsCaptured(): void
    {
        $payxy = $this->payxy(self::ok([], headers: ['X-Request-Id' => 'req-42']));

        self::assertSame('req-42', $payxy->payments()->verify('ORDER-1')->getRequestId());
    }

    public function testEmptySuccessBodyIsAllowed(): void
    {
        $payxy = $this->payxy(new Response(204));

        self::assertSame([], $payxy->payments()->verify('ORDER-1')->toArray());
    }
}
