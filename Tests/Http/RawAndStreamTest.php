<?php

namespace Omnisense\Contracts\Tests\Http;

use Omnisense\Contracts\Exception\ProviderException;
use Omnisense\Contracts\Http\Data;
use Omnisense\Contracts\Http\JsonClient;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\LegacyReset;
use Omnisense\Contracts\Policy\RateLimitHeaders;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Testing\MockHttp;
use Omnisense\Contracts\Testing\RecordedHttpClient;
use Omnisense\Contracts\Testing\RecordedResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Empty bodies, raw calls, several header values, the legacy rate fields, recorded answers streamed. */
final class RawAndStreamTest extends TestCase
{
    public function testAnEmptyBodyIsAnEmptyData(): void
    {
        $client = new JsonClient(RecordedHttpClient::routes(['~/start$~' => [204, ''], '~/stop$~' => [304, "  \n"], '~/html$~' => [200, '<h1>Hi</h1>']]), 'Engine');

        self::assertSame([], $client->request('POST', 'https://engine.example/containers/1/start')?->toArray());
        self::assertSame([], $client->request('POST', 'https://engine.example/containers/1/stop')?->toArray());
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The body is not JSON.');
        $client->get('https://engine.example/html');
    }

    public function testAFamilyReadsABodyItsOwnWay(): void
    {
        $client = new class(RecordedHttpClient::routes(['~~' => [200, "{\"a\":1}\n{\"a\":2}\n"]]), 'Lines') extends JsonClient {
            protected function decode(string $body, string $what, int $status): Data
            {
                return new Data(array_map(static fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", $body)))));
            }
        };

        self::assertSame([1, 2], array_map(static fn (Data $d) => $d->int('a'), $client->get('https://lines.example/feed')?->items() ?? []));
    }

    public function testARawCallGivesTheStatusTheHeadersTheBodyAndTheUrlThatAnswered(): void
    {
        $http = new class implements HttpClientInterface {
            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return match ($method) {
                    'PROPFIND' => new RecordedResponse(207, '<d:multistatus xmlns:d="DAV:"/>', ['Content-Type' => ['application/xml'], 'DAV' => ['1, 2, calendar-access']], 'https://dav.example/calendars/me/'),
                    'PUT' => new RecordedResponse(412, 'changed', [], $url),
                    default => new RecordedResponse(500, 'down', [], $url),
                };
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('not streamed');
            }

            /** @param array<string, mixed> $options */
            public function withOptions(array $options): static
            {
                return $this;
            }
        };
        $client = new JsonClient($http, 'Dav');

        $answer = $client->raw('PROPFIND', 'https://dav.example/me', headers: ['Depth' => '1']);
        self::assertSame(207, $answer->status);
        self::assertSame('application/xml', $answer->header('content-type'));
        self::assertSame(['1, 2, calendar-access'], $answer->headers['dav']);
        self::assertStringContainsString('multistatus', $answer->body);
        self::assertSame('https://dav.example/calendars/me/', $answer->url, 'the URL after redirects');
        self::assertSame([], $answer->json()->toArray(), 'XML is no JSON: an empty Data');
        self::assertSame(412, $client->raw('PUT', 'https://dav.example/me/event.ics', pass: [404, 412])->status);
        try {
            $client->raw('DELETE', 'https://dav.example/me/event.ics');
            self::fail('A 500 went through.');
        } catch (ProviderException $e) {
            self::assertSame(500, $e->status(), 'the status is the exception\'s code');
        }
        self::assertSame(0, ProviderException::answered('Dav', 'GET /', 200, 'The body is not JSON.')->status());
    }

    public function testAHeaderMaySendSeveralValues(): void
    {
        $http = MockHttp::routes(['~~' => [200, '{}']]);
        $client = new JsonClient($http->client, 'Graph', ['Prefer' => ['outlook.timezone="UTC"', 'IdType="ImmutableId"']]);

        $client->get('https://graph.example/me/events');
        self::assertSame('outlook.timezone="UTC", IdType="ImmutableId"', $http->header('prefer'));
        $client->raw('GET', 'https://graph.example/me', headers: ['Accept' => ['application/json', 'text/plain']]);
        self::assertSame('application/json, text/plain', $http->header('accept'));
        self::assertSame(['GET https://graph.example/me/events', 'GET https://graph.example/me'], $http->lines());
    }

    public function testTheLegacyRateFieldsAreReadOnlyWhenTheProviderAsks(): void
    {
        $now = 1_762_790_000;
        $fields = ['X-RateLimit-Limit' => '5000', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($now + 90)];

        self::assertNull(RateLimitHeaders::wait(200, $fields, $now), 'by default, only Retry-After and the IETF fields');
        self::assertSame(90, RateLimitHeaders::wait(200, $fields, $now, LegacyReset::Epoch));
        self::assertSame(30, RateLimitHeaders::wait(200, ['Ratelimit-Remaining' => '0', 'Ratelimit-Reset' => '30'], $now, LegacyReset::Seconds));
        self::assertNull(RateLimitHeaders::wait(200, ['X-RateLimit-Remaining' => '12', 'X-RateLimit-Reset' => '30'], $now, LegacyReset::Seconds), 'something left: no wait');
        self::assertSame(30, RateLimitHeaders::wait(429, ['X-RateLimit-Remaining' => '3', 'X-RateLimit-Reset' => '30'], $now, LegacyReset::Seconds), 'a 429 waits for the reset');
        self::assertSame(5, RateLimitHeaders::wait(429, ['Retry-After' => '5', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '30'], $now, LegacyReset::Seconds), 'Retry-After prevails');

        $limiter = new InMemoryRateLimiter();
        $backoff = new Backoff($limiter, 'twitch');
        $backoff->observe(200, ['ratelimit-remaining' => ['0'], 'ratelimit-reset' => ['60']]);
        self::assertSame(0, $backoff->left());
        $backoff->withLegacyHeaders(LegacyReset::Seconds)->observe(200, ['ratelimit-remaining' => ['0'], 'ratelimit-reset' => ['60']]);
        self::assertSame(60, $backoff->left(), 'the same wait, shared with the guard\'s backoff');
    }

    public function testAProviderHoldsTheNextCallsOnASignalOfItsOwn(): void
    {
        $backoff = new Backoff(new InMemoryRateLimiter(), 'google');
        $e = $backoff->hold(60, 'google answered 403 rateLimitExceeded.');

        self::assertSame('google answered 403 rateLimitExceeded: try again in 60 s.', $e->getMessage());
        self::assertSame(60, $e->retryAfter);
        self::assertSame(60, $backoff->left());
        $this->expectException(UsageLimitReached::class);
        $backoff->clear();
    }

    /**
     * Symfony's PSR-18 bridge (Psr18Client) reads a response's body through
     * StreamWrapper over the client's stream(): a recorded answer reads the
     * same, chunk after chunk, once.
     */
    public function testRecordedAnswersStreamAsThePsr18BridgeReadsThem(): void
    {
        $recorded = RecordedHttpClient::routes(['~/films/\d+$~' => [200, '{"title":"Vertigo"}', ['etag' => ['"v1"']]]]);
        $response = $recorded->request('GET', 'https://films.example/films/1');

        $chunks = [];
        foreach ($recorded->stream($response) as $from => $chunk) {
            self::assertSame($response, $from);
            $chunks[] = [$chunk->isFirst(), $chunk->isLast(), $chunk->getContent()];
        }
        self::assertSame([[true, false, ''], [false, false, '{"title":"Vertigo"}'], [false, true, '']], $chunks);
        self::assertCount(0, iterator_to_array($recorded->stream([$response]), false), 'its chunks are yielded once');

        $resource = StreamWrapper::createResource($recorded->request('GET', 'https://films.example/films/2'), $recorded);
        self::assertSame('{"title":"Vertigo"}', stream_get_contents($resource));
        self::assertSame(404, $recorded->request('GET', 'https://films.example/none')->getStatusCode());
    }

    public function testARouteMayMatchTheRequestBody(): void
    {
        $http = RecordedHttpClient::routes([
            '~^POST .+/translate$~' => [200, '{"text":"Hallo"}', [], '~"target_lang":"DE"~'],
            '~^POST .+/translate~' => [200, '{"text":"Bonjour"}'],
            '~^POST .+/form$~' => [200, '{"ok":true}', [], '~(^|&)grant_type=refresh_token(&|$)~'],
        ]);
        $client = new JsonClient($http, 'Translator');

        self::assertSame('Hallo', $client->request('POST', 'https://t.example/translate', ['json' => ['text' => 'Hello', 'target_lang' => 'DE']])?->string('text'));
        self::assertSame('Bonjour', $client->request('POST', 'https://t.example/translate', ['json' => ['text' => 'Hello', 'target_lang' => 'FR']])?->string('text'));
        self::assertTrue($client->request('POST', 'https://t.example/form', ['body' => ['grant_type' => 'refresh_token']])?->bool('ok'));
        self::assertNull($client->request('POST', 'https://t.example/form', ['body' => ['grant_type' => 'password']]), 'no route: 404');
    }
}
