<?php

namespace Omnisense\Contracts\Tests\Http;

use Omnisense\Contracts\Http\NoRetry;
use Omnisense\Contracts\Testing\MockHttp;
use Omnisense\Contracts\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NoRetryTest extends TestCase
{
    /** @var list<string> "METHOD url" of each request that reached the wire */
    private array $sent = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    public function testTheRetryingClientIsNotBlind(): void
    {
        $this->answers = [self::unavailable(), self::ok()];

        $response = $this->retrying()->request('POST', 'https://api.example/payments', ['json' => ['amount' => 1200]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['POST https://api.example/payments', 'POST https://api.example/payments'], $this->sent, 'Symfony resends a POST answered 503');
    }

    public function testAPostAnswered503IsSentOnce(): void
    {
        $this->answers = [self::unavailable(), self::ok()];

        $response = NoRetry::of($this->retrying())->request('POST', 'https://api.example/payments', ['json' => ['amount' => 1200]]);

        self::assertSame(503, $response->getStatusCode(), 'the 503 reaches the provider');
        self::assertSame(['POST https://api.example/payments'], $this->sent);
        self::assertCount(1, $this->answers, 'the second answer was never asked for');
    }

    public function testAPostAnswered429OrATransportErrorIsSentOnce(): void
    {
        $this->answers = [new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]), self::ok()];
        self::assertSame(429, NoRetry::of($this->retrying())->request('POST', 'https://api.example/labels')->getStatusCode());
        self::assertCount(1, $this->sent);

        $this->sent = [];
        $this->answers = [new MockResponse('', ['error' => 'connection reset']), self::ok()];
        $response = NoRetry::forWrites($this->retrying())->request('PUT', 'https://api.example/records/1');
        try {
            $response->getStatusCode();
            self::fail('a transport error');
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
        }
        self::assertCount(1, $this->sent, 'a PUT is a write: sent once');
    }

    public function testAGetIsSentOnceByDefaultAndRetriedOnlyForWrites(): void
    {
        $this->answers = [self::unavailable(), self::ok()];
        self::assertSame(503, NoRetry::of($this->retrying())->request('GET', 'https://api.example/payments/1')->getStatusCode());
        self::assertCount(1, $this->sent, 'of(): nothing retried, reads included');

        $this->sent = [];
        $this->answers = [self::unavailable(), self::ok()];
        $client = NoRetry::forWrites($this->retrying());
        self::assertSame(200, $client->request('GET', 'https://api.example/payments/1')->getStatusCode());
        self::assertCount(2, $this->sent, 'forWrites(): a GET keeps the application\'s retries');

        $this->sent = [];
        $this->answers = [self::unavailable(), self::ok()];
        self::assertSame(503, $client->request('POST', 'https://api.example/payments')->getStatusCode());
        self::assertCount(1, $this->sent, 'forWrites(): a POST is sent once');
    }

    public function testTheRetryIsTurnedOffThroughSymfonysDecorators(): void
    {
        $this->answers = [self::unavailable(), self::ok()];
        // As framework.http_client builds it: Traceable(Retryable(Scoping(transport))).
        $http = new TraceableHttpClient(new RetryableHttpClient(ScopingHttpClient::forBaseUri($this->transport(), 'https://api.example/'), self::strategy()));

        self::assertSame(503, NoRetry::of($http)->request('POST', 'payments')->getStatusCode());
        self::assertSame(['POST https://api.example/payments'], $this->sent);
    }

    public function testForWritesStaysSoThroughWithOptions(): void
    {
        $this->answers = [self::unavailable(), self::ok()];
        $client = NoRetry::forWrites($this->retrying())->withOptions(['headers' => ['x-site' => 'test'], 'max_retries' => 3]);

        self::assertSame(503, $client->request('POST', 'https://api.example/payments')->getStatusCode());
        self::assertCount(1, $this->sent);
        self::assertSame($client, NoRetry::forWrites($client));
    }

    public function testClientsWithoutRetriesAndTestClientsAreKeptAsGiven(): void
    {
        $mock = new MockHttpClient();
        $recorded = RecordedHttpClient::routes([]);
        $kit = MockHttp::routes([]);
        $plain = new NativeHttpClient();

        foreach ([$mock, $recorded, $kit->client, $plain] as $http) {
            self::assertSame($http, NoRetry::of($http), $http::class);
            self::assertSame($http, NoRetry::forWrites($http), $http::class);
        }
        self::assertTrue(NoRetry::isTestClient($mock));
        self::assertTrue(NoRetry::isTestClient($recorded));
        self::assertFalse(NoRetry::isTestClient($plain));
    }

    public function testAMockHttpClientKeepsCountingItsRequests(): void
    {
        $mock = new MockHttpClient([self::ok()]);
        NoRetry::of($mock)->request('POST', 'https://api.example/payments')->getStatusCode();

        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testAMockHttpClientUnderDecoratorsIsNotCopied(): void
    {
        // The framework's test transport under its decorators, retries on: the very same MockHttpClient answers and counts.
        $mock = new MockHttpClient([self::unavailable(), self::unavailable(), self::ok()]);
        $http = new TraceableHttpClient(new RetryableHttpClient($mock, self::strategy()));

        self::assertSame(503, NoRetry::of($http)->request('POST', 'https://api.example/payments')->getStatusCode());
        self::assertSame(1, $mock->getRequestsCount());
        self::assertSame(503, NoRetry::forWrites($http)->request('POST', 'https://api.example/payments')->getStatusCode());
        self::assertSame(2, $mock->getRequestsCount(), 'the second answer, once more sent once');

        $plain = new MockHttpClient([self::unavailable()]);
        self::assertSame(503, NoRetry::of(new TraceableHttpClient($plain))->request('POST', 'https://api.example/payments')->getStatusCode());
        self::assertSame(1, $plain->getRequestsCount(), 'no retry under it: the same MockHttpClient still');
    }

    private function retrying(): HttpClientInterface
    {
        return new RetryableHttpClient($this->transport(), self::strategy(), 3);
    }

    private function transport(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->sent[] = $method.' '.$url;

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }

    /** Symfony's default statuses and methods, without the wait. */
    private static function strategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0);
    }

    private static function unavailable(): MockResponse
    {
        return new MockResponse('{"error":"unavailable"}', ['http_code' => 503]);
    }

    private static function ok(): MockResponse
    {
        return new MockResponse('{"id":"pay_1"}');
    }
}
