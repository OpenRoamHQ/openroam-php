<?php

namespace OpenRoam\Test;

use OpenRoam\Retry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class RetryTest extends TestCase
{
    private const URL = 'https://sandbox.example/v2/orders';
    private const BODY = '{"quoteId":"q_1"}';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];
    /** @var int[] */
    private array $sleeps = [];
    /** @var array<int, array{0: int, 1: string, 2: int}> */
    private array $retries = [];

    /** A client whose transport answers with $queue, records every attempt and never really waits. */
    private function client(array $queue, array $options = []): Client
    {
        $this->history = [];
        $this->sleeps = [];
        $this->retries = [];
        $options += [
            'sleep' => function (int $ms): void {
                $this->sleeps[] = $ms;
            },
            'onRetry' => function (int $attempt, string $reason, int $ms): void {
                $this->retries[] = [$attempt, $reason, $ms];
            },
        ];
        $transport = Middleware::history($this->history)(new MockHandler($queue));
        return Retry::client($options, ['handler' => $transport, 'http_errors' => false]);
    }

    private function postWithKey(Client $client, string $key = 'b1c2d3e4-0000-4000-8000-000000000001'): Response
    {
        return $client->post(self::URL, ['headers' => ['Idempotency-Key' => $key, 'Content-Type' => 'application/json'], 'body' => self::BODY]);
    }

    private function inProgress(int $status, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'],
            '{"code":12002,"name":"IDEMPOTENCY_KEY_IN_PROGRESS","message":"Still running"}');
    }

    private function assertSameRequestResent(): void
    {
        $this->assertGreaterThan(1, count($this->history));
        $first = $this->history[0]['request'];
        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $this->assertSame($first->getMethod(), $request->getMethod());
            $this->assertSame((string) $first->getUri(), (string) $request->getUri());
            $this->assertSame($first->getHeaders(), $request->getHeaders());
            $this->assertSame(self::BODY, (string) $request->getBody());
        }
    }

    // 1
    public function testGet503Then200ReturnsThe200AfterOneRetry(): void
    {
        $response = $this->client([new Response(503), new Response(200, [], 'ok')])->get(self::URL);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
        $this->assertCount(2, $this->history);
        $this->assertCount(1, $this->retries);
        $this->assertSame([1, 'HTTP 503'], array_slice($this->retries[0], 0, 2));
        $this->assertLessThanOrEqual(500, $this->sleeps[0]);
    }

    // 2
    public function testGet429HonoursRetryAfterCappedAtMaxDelay(): void
    {
        $this->client([new Response(429, ['Retry-After' => '3']), new Response(200)])->get(self::URL);
        $this->assertCount(2, $this->history);
        $this->assertGreaterThanOrEqual(3000, $this->sleeps[0]);
        $this->assertLessThanOrEqual(30000, $this->sleeps[0]);

        $this->client([new Response(429, ['Retry-After' => '120']), new Response(200)], ['maxDelay' => 5000])->get(self::URL);
        $this->assertSame([5000], $this->sleeps);
    }

    // 3
    public function testPostWithKey503Then200ResendsTheSameKeyAndBody(): void
    {
        $response = $this->postWithKey($this->client([new Response(503), new Response(200)]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->history);
        $this->assertSameRequestResent();
        $this->assertSame('b1c2d3e4-0000-4000-8000-000000000001', $this->history[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    // 4
    public function testPostWithKey202InProgressThen200(): void
    {
        $response = $this->postWithKey($this->client([$this->inProgress(202), new Response(201, [], '{"id":"o_1"}')]));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(2, $this->history);
        $this->assertSameRequestResent();
        $this->assertSame([2000], $this->sleeps); // no Retry-After: 2 s
        $this->assertSame('in progress (12002)', $this->retries[0][1]);
    }

    // 5
    public function testPostWithKey409InProgressThen200UsesRetryAfter(): void
    {
        $response = $this->postWithKey($this->client([$this->inProgress(409, ['Retry-After' => '1']), new Response(200)]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->history);
        $this->assertSameRequestResent();
        $this->assertSame([1000], $this->sleeps);
    }

    public function testInProgressHasItsOwnBudgetAndReturnsTheLastResponseReadable(): void
    {
        $queue = array_map(fn () => $this->inProgress(202), range(1, 5));
        $response = $this->postWithKey($this->client($queue, ['inProgressRetries' => 2, 'maxRetries' => 0]));
        $this->assertCount(3, $this->history);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertStringContainsString('12002', (string) $response->getBody());
    }

    // 6
    public function testPostWithKey409OtherCodeIsReturnedAfterOneRequest(): void
    {
        foreach ([12001, 12000] as $code) {
            $response = $this->postWithKey($this->client([new Response(409, [], '{"code":' . $code . '}'), new Response(200)]));
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('{"code":' . $code . '}', (string) $response->getBody());
            $this->assertCount(1, $this->history);
        }
    }

    // 7
    public function testPostWithKey500IsReturnedAfterOneRequest(): void
    {
        $response = $this->postWithKey($this->client([new Response(500), new Response(200)]));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $this->history);
    }

    // 8
    public function testPostWithoutKeyIsNotRetriedOn503ButIsOn429(): void
    {
        $response = $this->client([new Response(503), new Response(200)])->post(self::URL, ['body' => self::BODY]);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertCount(1, $this->history);

        $response = $this->client([new Response(429, ['Retry-After' => '1']), new Response(200)])->post(self::URL, ['body' => self::BODY]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->history);
        $this->assertSameRequestResent();
    }

    public function testPostWithoutKeyIsNotRetriedOnInProgressOrNetworkError(): void
    {
        $response = $this->client([$this->inProgress(202), new Response(200)])->post(self::URL, ['body' => self::BODY]);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertCount(1, $this->history);

        $client = $this->client([new ConnectException('refused', new Request('POST', self::URL)), new Response(200)]);
        try {
            $client->post(self::URL, ['body' => self::BODY]);
            $this->fail('expected the network error');
        } catch (ConnectException $e) {
            $this->assertSame('refused', $e->getMessage());
        }
        $this->assertCount(1, $this->history);
    }

    /**
     * 9
     * @dataProvider clientErrors
     */
    public function testClientErrorsAreReturnedAfterOneRequest(int $status): void
    {
        $response = $this->postWithKey($this->client([new Response($status), new Response(200)]));
        $this->assertSame($status, $response->getStatusCode());
        $this->assertCount(1, $this->history);

        $response = $this->client([new Response($status), new Response(200)])->get(self::URL);
        $this->assertSame($status, $response->getStatusCode());
        $this->assertCount(1, $this->history);
    }

    public static function clientErrors(): array
    {
        return ['400' => [400], '401' => [401], '404' => [404]];
    }

    // 10
    public function testGetNetworkErrorThenSuccess(): void
    {
        $response = $this->client([new ConnectException('timed out', new Request('GET', self::URL)), new Response(200)])->get(self::URL);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->history);
        $this->assertSame('network error', $this->retries[0][1]);
    }

    public function testNetworkErrorsExhaustedSurfaceTheLastError(): void
    {
        $queue = array_map(fn ($i) => new ConnectException('down ' . $i, new Request('GET', self::URL)), range(1, 5));
        try {
            $this->client($queue, ['maxRetries' => 2])->get(self::URL);
            $this->fail('expected the network error');
        } catch (ConnectException $e) {
            $this->assertSame('down 3', $e->getMessage());
        }
        $this->assertCount(3, $this->history);
    }

    // 11
    public function testRetriesExhaustedReturnTheLast503(): void
    {
        $queue = [new Response(503, [], 'a'), new Response(503, [], 'b'), new Response(503, [], 'c'), new Response(503, [], 'd'), new Response(200)];
        $response = $this->postWithKey($this->client($queue));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('d', (string) $response->getBody());
        $this->assertCount(3 + 1, $this->history);
        $this->assertSame([1, 2, 3], array_column($this->retries, 0));
        foreach ($this->sleeps as $i => $ms) {
            $this->assertLessThanOrEqual(500 * (2 ** $i), $ms); // full jitter
        }
    }

    // 12
    public function testRetryAfterAsAnHttpDate(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 10) . ' GMT';
        $this->client([new Response(503, ['Retry-After' => $date]), new Response(200)])->get(self::URL);
        $this->assertGreaterThanOrEqual(8000, $this->sleeps[0]);
        $this->assertLessThanOrEqual(10000, $this->sleeps[0]);
    }

    /** @dataProvider ignoredRetryAfter */
    public function testAnInvalidRetryAfterIsIgnored(string $value): void
    {
        $this->client([new Response(503, ['Retry-After' => $value]), new Response(200)], ['baseDelay' => 10])->get(self::URL);
        $this->assertCount(2, $this->history);
        $this->assertLessThanOrEqual(10, $this->sleeps[0]);
    }

    public static function ignoredRetryAfter(): array
    {
        return ['text' => ['soon'], 'negative' => ['-5'], 'past date' => ['Mon, 01 Jan 2001 00:00:00 GMT'], 'fraction' => ['1.5']];
    }

    // 13
    public function testNewIdempotencyKeyIsAFreshUuidV4(): void
    {
        $keys = array_map(fn () => Retry::newIdempotencyKey(), range(1, 50));
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        }
        $this->assertCount(50, array_unique($keys));
    }

    public function testAnUnknownOptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Retry::middleware(['maxRetry' => 1]);
    }

    public function testTheGeneratedApiUsesTheRetryingClient(): void
    {
        $client = $this->client([new Response(503), new Response(200, ['Content-Type' => 'application/json'], '{}')]);
        $config = (new \OpenRoam\Configuration())->setApiKey('X-Partner-Key', 'key')->setHost('https://sandbox.example');
        [, $status] = (new \OpenRoam\Api\EsimsApi($client, $config))->esimsSearchWithHttpInfo(country_code: 'JP');
        $this->assertSame(200, $status);
        $this->assertCount(2, $this->history);
    }

    public function testWithoutACustomSleepTheWaitIsGuzzlesDelayOptionNotABlockingSleep(): void
    {
        $history = [];
        $transport = Middleware::history($history)(new MockHandler([new Response(503, ['Retry-After' => '0']), new Response(200)]));
        $client = Retry::client(['baseDelay' => 0], ['handler' => $transport, 'http_errors' => false]);

        $this->assertSame(200, $client->get(self::URL)->getStatusCode());
        $this->assertCount(2, $history);
        $this->assertArrayNotHasKey('delay', $history[0]['options']);
        $this->assertArrayHasKey('delay', $history[1]['options']);
    }
}
