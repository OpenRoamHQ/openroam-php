<?php

declare(strict_types=1);

namespace OpenRoam\Test;

use OpenRoam\Model\EsimOrderRequest;
use OpenRoam\ObjectSerializer;
use OpenRoam\OpenRoam;
use OpenRoam\OpenRoamException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ClientTest extends TestCase
{
    private const BASE = 'http://127.0.0.1:9';
    private const BODY = ['currency' => 'USD', 'items' => [['publicId' => 'esim_1', 'quantity' => 1]]];
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var array<int, array{request: RequestInterface, options: array}> */
    private array $history = [];

    protected function tearDown(): void
    {
        putenv('OPENROAM_API_KEY');
        unset($_ENV['OPENROAM_API_KEY'], $_SERVER['OPENROAM_API_KEY']);
    }

    /**
     * A transport that answers each request with the next scripted response (or throws the scripted error) and records
     * every request it got, retries included.
     */
    private function handler(array $script): callable
    {
        $this->history = [];
        return Middleware::history($this->history)(new MockHandler($script));
    }

    private function client(array $script, array $options = []): OpenRoam
    {
        return new OpenRoam($options + ['apiKey' => 'k', 'baseUrl' => self::BASE, 'handler' => $this->handler($script)]);
    }

    private static function json(int $status, mixed $body = [], array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body === [] ? new \stdClass() : $body));
    }

    private static function ok(): Response
    {
        return self::json(200, ['items' => [], 'pagination' => new \stdClass()]);
    }

    private function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    private function target(int $index = 0): string
    {
        $uri = $this->request($index)->getUri();
        return $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
    }

    /** Runs $call and returns the OpenRoamException it threw. */
    private function failure(callable $call): OpenRoamException
    {
        try {
            $call();
        } catch (OpenRoamException $error) {
            return $error;
        }
        $this->fail('Expected an OpenRoamException.');
    }

    public function testASearchIsOneCallOnTheProductWithTheKeySentAsXPartnerKey(): void
    {
        $openroam = new OpenRoam(['apiKey' => 'secret', 'baseUrl' => self::BASE, 'handler' => $this->handler([self::ok()])]);
        $found = $openroam->esims->search(['countryCode' => 'JP']);
        $this->assertSame([], $found->getItems());
        $this->assertSame('GET', $this->request()->getMethod());
        $this->assertSame('/v1/esims/search?countryCode=JP', $this->target());
        $this->assertSame('secret', $this->request()->getHeaderLine('X-Partner-Key'));
    }

    public function testAKeyAloneWorksAndTheKeyFallsBackToOpenroamApiKey(): void
    {
        $this->assertNotNull((new OpenRoam('key'))->esims);
        putenv('OPENROAM_API_KEY=from-env');
        (new OpenRoam(['baseUrl' => self::BASE, 'handler' => $this->handler([self::ok()])]))->esims->search();
        $this->assertSame('from-env', $this->request()->getHeaderLine('X-Partner-Key'));

        putenv('OPENROAM_API_KEY');
        $error = $this->failure(fn () => new OpenRoam());
        $this->assertStringContainsString('Missing API key', $error->getMessage());
        $this->assertNull($error->getStatus());
    }

    public function testTheEnvironmentPicksTheUrlAndAnUnknownOneIsRefused(): void
    {
        (new OpenRoam(['apiKey' => 'k', 'handler' => $this->handler([self::ok()])]))->esims->search();
        $this->assertSame('https://api.openroam.com/v1/esims/search', (string) $this->request()->getUri());
        (new OpenRoam(['apiKey' => 'k', 'environment' => 'sandbox', 'handler' => $this->handler([self::ok()])]))->esims->search();
        $this->assertSame('https://api.sandbox.openroam.com/v1/esims/search', (string) $this->request()->getUri());

        $this->assertStringContainsString('Unknown environment', $this->failure(fn () => new OpenRoam(['apiKey' => 'k', 'environment' => 'staging']))->getMessage());
        $this->assertStringContainsString('Unknown option', $this->failure(fn () => new OpenRoam(['apiKey' => 'k', 'retries' => 3]))->getMessage());
        $this->assertStringContainsString('maxRetries', $this->failure(fn () => new OpenRoam(['apiKey' => 'k', 'maxRetries' => -1]))->getMessage());
    }

    public function testATrailingSlashOnBaseUrlIsIgnored(): void
    {
        $this->client([self::ok()], ['baseUrl' => self::BASE . '///'])->esims->search();
        $this->assertSame(self::BASE . '/v1/esims/search', (string) $this->request()->getUri());
    }

    public function testAQuoteSendsTheBodyAsGivenNotWrappedInAnObject(): void
    {
        $this->client([self::json(200, ['currency' => 'USD'])])->esims->quote(self::BODY);
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/v1/esims/quote', $this->target());
        $this->assertSame(self::BODY, json_decode((string) $this->request()->getBody(), true));
        $this->assertStringContainsString('application/json', $this->request()->getHeaderLine('Content-Type'));
        $this->assertFalse($this->request()->hasHeader('Idempotency-Key'));
    }

    public function testTheGeneratedModelIsSentFlatToo(): void
    {
        $model = ObjectSerializer::deserialize(json_decode(json_encode(self::BODY)), EsimOrderRequest::class);
        $this->assertInstanceOf(EsimOrderRequest::class, $model);
        $this->client([self::json(200, ['currency' => 'USD'])])->esims->quote($model);
        $sent = json_decode((string) $this->request()->getBody(), true);
        $this->assertSame('USD', $sent['currency']);
        $this->assertSame('esim_1', $sent['items'][0]['publicId']);
        $this->assertSame(1, $sent['items'][0]['quantity']);
    }

    public function testAnArrayBodyWritesItsDatesAsTheModelDoesAndKeepsFieldsTheModelDoesNotKnow(): void
    {
        $openroam = $this->client([self::ok(), self::ok()]);
        $openroam->hotels->search([
            'checkIn' => new \DateTimeImmutable('2026-03-01T15:00:00Z'),
            'checkOut' => new \DateTime('2026-03-04'),
            'rooms' => [['adults' => 2]],
            'notInTheModelYet' => ['since' => '2026'],
        ]);
        $sent = json_decode((string) $this->request()->getBody(), true);
        $this->assertSame('2026-03-01', $sent['checkIn']);
        $this->assertSame('2026-03-04', $sent['checkOut']);
        $this->assertSame([['adults' => 2]], $sent['rooms']);
        $this->assertSame(['since' => '2026'], $sent['notInTheModelYet']);

        $openroam->flights->search(['slices' => [['origin' => 'LIS', 'destination' => 'JFK', 'departureDate' => new \DateTimeImmutable('2026-05-06')]]]);
        $sent = json_decode((string) $this->request(1)->getBody(), true);
        $this->assertSame('2026-05-06', $sent['slices'][0]['departureDate']);
    }

    public function testAHandlerStackOfYoursIsNotChangedByTheRetryLayerOfAClient(): void
    {
        $this->history = [];
        $transport = new MockHandler([self::json(503), self::json(503, [], ['Retry-After' => '0']), self::ok()]);
        $stack = \GuzzleHttp\HandlerStack::create(Middleware::history($this->history)($transport));
        $retrying = new OpenRoam(['apiKey' => 'k', 'baseUrl' => self::BASE, 'handler' => $stack, 'maxRetries' => 3]);
        $plain = new OpenRoam(['apiKey' => 'k', 'baseUrl' => self::BASE, 'handler' => $stack]);

        $this->assertSame(503, $this->failure(fn () => $plain->esims->search())->getStatus());
        $this->assertCount(1, $this->history, 'a client without retries sends once, even on a stack another client retries with');
        $retrying->esims->search();
        $this->assertCount(3, $this->history, 'the client with retries still retries');
    }

    public function testAnOrderGetsItsOwnIdempotencyKeyAndYourOwnKeyWins(): void
    {
        $openroam = $this->client([self::ok(), self::ok(), self::ok()]);
        $openroam->esims->order(self::BODY);
        $openroam->esims->order(self::BODY);
        $openroam->esims->order(self::BODY, ['idempotencyKey' => 'my-order-42']);
        [$first, $second, $third] = array_map(fn ($entry) => $entry['request']->getHeaderLine('Idempotency-Key'), $this->history);
        $this->assertMatchesRegularExpression(self::UUID_V4, $first);
        $this->assertNotSame($first, $second);
        $this->assertSame('my-order-42', $third);
        $this->assertSame(self::BODY, json_decode((string) $this->request()->getBody(), true));
    }

    public function testAnOptionTheMethodDoesNotTakeIsRefused(): void
    {
        $openroam = $this->client([]);
        $this->assertStringContainsString('Unknown call option', $this->failure(fn () => $openroam->esims->quote(self::BODY, ['idempotencyKey' => 'x']))->getMessage());
        $this->assertStringContainsString('idempotencyKey', $this->failure(fn () => $openroam->esims->order(self::BODY, ['idempotencyKey' => '']))->getMessage());
        $this->assertSame([], $this->history);
    }

    public function testATimeoutIsPassedToTheTransport(): void
    {
        $this->client([self::ok()])->esims->search([], ['timeout' => 2.5]);
        $this->assertSame(2.5, $this->history[0]['options']['timeout']);
    }

    public function testPathIdsComeFirstInPathOrderAndQueryParametersAreOneArray(): void
    {
        $openroam = $this->client([self::ok(), self::ok(), self::ok()]);
        $openroam->esims->getUsage('8988-1');
        $openroam->orderItems->getChange('item-1', 'change-2');
        $openroam->esims->listUsageHistory('8988-1', ['limit' => 5]);
        $this->assertSame('/v1/esims/8988-1/usage', $this->target(0));
        $this->assertSame('/v1/order-items/item-1/changes/change-2', $this->target(1));
        $this->assertSame('/v1/esims/8988-1/usage/history?limit=5', $this->target(2));
    }

    public function testAStrayEntryInTheQueryArrayCannotReplaceAPositionalId(): void
    {
        $this->client([self::ok()])->esims->getDestination('A', ['id' => 'B', 'currency' => 'USD']);
        $this->assertSame('/v1/esims/destinations/A?currency=USD', $this->target());
    }

    public function testAPathIdIsEncoded(): void
    {
        $this->client([self::ok()])->esims->getUsage('a/b c');
        $this->assertSame('/v1/esims/a%2Fb%20c/usage', $this->target());
    }

    public function testADateQueryParameterAcceptsADateOrAString(): void
    {
        $openroam = $this->client([self::ok(), self::ok()]);
        $openroam->esims->listUsageHistory('1', ['from' => new \DateTimeImmutable('2026-01-02T03:04:05Z')]);
        $openroam->esims->listUsageHistory('1', ['from' => '2026-01-02T03:04:05Z']);
        foreach ([0, 1] as $index) {
            parse_str($this->request($index)->getUri()->getQuery(), $query);
            $this->assertSame(strtotime('2026-01-02T03:04:05Z'), strtotime($query['from']));
        }
    }

    public function testAnOrderItemChangePostsWithAKey(): void
    {
        $answer = self::json(200, ['changeId' => '1b4e28ba-2fa1-4d2a-883f-0016d3cca427', 'status' => 'pendingReview']);
        $this->client([$answer])->orderItems->change('item-1', ['operation' => 'request']);
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/v1/order-items/item-1/changes', $this->target());
        $this->assertMatchesRegularExpression(self::UUID_V4, $this->request()->getHeaderLine('Idempotency-Key'));
        $this->assertSame(['operation' => 'request'], json_decode((string) $this->request()->getBody(), true));
    }

    public function testAFailedCallThrowsAnOpenRoamExceptionCarryingTheErrorBody(): void
    {
        $body = ['code' => 11002, 'name' => 'PRICE_ABOVE_LIMIT', 'message' => 'The price is above your limit.', 'requestId' => 'req_9',
            'details' => [['path' => 'items[0].inputs.maxPrice', 'reason' => 'PRICE_ABOVE_LIMIT']]];
        $openroam = $this->client([self::json(409, $body)]);
        $error = $this->failure(fn () => $openroam->esims->quote(self::BODY));
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame(409, $error->getStatus());
        $this->assertSame(11002, $error->getCode());
        $this->assertSame('PRICE_ABOVE_LIMIT', $error->getErrorName());
        $this->assertSame('The price is above your limit.', $error->getMessage());
        $this->assertSame('req_9', $error->getRequestId());
        $this->assertSame($body['details'], $error->getDetails());
    }

    public function testAnErrorAnswerThatIsNotJsonStillReportsItsStatus(): void
    {
        $openroam = $this->client([new Response(502, ['Content-Type' => 'text/html'], '<html>bad gateway</html>')]);
        $error = $this->failure(fn () => $openroam->esims->search());
        $this->assertSame(502, $error->getStatus());
        $this->assertSame(0, $error->getCode());
        $this->assertNull($error->getErrorName());
        $this->assertStringContainsString('502', $error->getMessage());
    }

    public function testNoAnswerAtAllIsAnOpenRoamExceptionWithoutAStatus(): void
    {
        $cause = new ConnectException('Connection refused', new Request('GET', self::BASE));
        $openroam = $this->client([$cause]);
        $error = $this->failure(fn () => $openroam->esims->search());
        $this->assertNull($error->getStatus());
        $this->assertSame(0, $error->getCode());
        $this->assertSame($cause, $error->getPrevious());
        $this->assertStringContainsString('did not get an answer', $error->getMessage());
    }

    public function testRetriesAreOffUntilMaxRetriesIsSetThenAnOrderIsSentAgainWithTheSameKeyAndBody(): void
    {
        $openroam = $this->client([self::json(503), self::ok()]);
        $this->assertSame(503, $this->failure(fn () => $openroam->esims->order(self::BODY))->getStatus());
        $this->assertCount(1, $this->history);

        $this->client([self::json(503, [], ['Retry-After' => '0']), self::ok()], ['maxRetries' => 2])->esims->order(self::BODY);
        $this->assertCount(2, $this->history);
        $this->assertSame($this->request(0)->getHeaderLine('Idempotency-Key'), $this->request(1)->getHeaderLine('Idempotency-Key'));
        $this->assertMatchesRegularExpression(self::UUID_V4, $this->request(1)->getHeaderLine('Idempotency-Key'));
        $this->assertSame((string) $this->request(0)->getBody(), (string) $this->request(1)->getBody());
    }

    public function testWithRetriesOnANetworkErrorOnAnOrderIsRetriedWithYourKey(): void
    {
        $cause = new ConnectException('Connection reset', new Request('POST', self::BASE));
        $this->client([$cause, self::ok()], ['maxRetries' => 1])->esims->order(self::BODY, ['idempotencyKey' => 'my-order-42']);
        $this->assertCount(2, $this->history);
        $this->assertSame('my-order-42', $this->request(1)->getHeaderLine('Idempotency-Key'));
    }

    public function testA202ThatSaysStillRunningIsAnErrorNotAnOrderAndAnother202IsReturned(): void
    {
        $running = ['code' => 12002, 'name' => 'IDEMPOTENCY_KEY_IN_PROGRESS', 'message' => 'Still running.', 'requestId' => 'req_1'];
        $openroam = $this->client([self::json(202, $running)]);
        $error = $this->failure(fn () => $openroam->esims->order(self::BODY));
        $this->assertSame(202, $error->getStatus());
        $this->assertSame(12002, $error->getCode());
        $this->assertSame('IDEMPOTENCY_KEY_IN_PROGRESS', $error->getErrorName());
        $this->assertSame('req_1', $error->getRequestId());

        $found = $this->client([self::json(202, ['items' => [], 'pagination' => new \stdClass()])])->esims->search();
        $this->assertSame([], $found->getItems());
    }

    public function testWithRetriesOnA12002AnswerIsRetriedAndTheFinalAnswerWins(): void
    {
        $this->client([self::json(202, ['code' => 12002], ['Retry-After' => '0']), self::ok()], ['maxRetries' => 2])->esims->order(self::BODY);
        $this->assertCount(2, $this->history);
    }

    public function testAMistakeInTheCallItselfIsAlsoAnOpenRoamExceptionWithTheOriginalAsPrevious(): void
    {
        $openroam = $this->client([]);
        $error = $this->failure(fn () => $openroam->esims->search(['limit' => 'five']));
        $this->assertNull($error->getStatus());
        $this->assertInstanceOf(\TypeError::class, $error->getPrevious());
        $this->assertSame([], $this->history);
    }

    public function testAnAnswerThatCannotBeReadIsAnOpenRoamExceptionWithItsStatus(): void
    {
        $openroam = $this->client([new Response(200, ['Content-Type' => 'application/json'], 'not json')]);
        $error = $this->failure(fn () => $openroam->esims->search());
        $this->assertSame(200, $error->getStatus());
        $this->assertNotNull($error->getPrevious());
    }

    public function testARouteWithoutAnAnswerBodyReturnsNothing(): void
    {
        $this->client([new Response(204)])->wallets->setDefault('wallet-1');
        $this->assertSame('PATCH', $this->request()->getMethod());
        $this->assertSame('/v1/wallets/select/wallet-1', $this->target());
    }

    public function testEveryApiRouteHasItsMethodWithTheAgreedNames(): void
    {
        $methods = [];
        $walk = function (object $resource, string $prefix) use (&$walk, &$methods): void {
            $class = new \ReflectionClass($resource);
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $walk($property->getValue($resource), $prefix . $property->getName() . '.');
            }
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor() && !$method->isStatic()) {
                    $methods[] = $prefix . $method->getName();
                }
            }
        };
        $walk(new OpenRoam('k'), '');
        $this->assertCount(73, $methods);
        $this->assertCount(73, array_unique($methods));
        foreach (['esims.search', 'esims.quote', 'esims.order', 'esims.listDailyUsage', 'esims.listCatalog', 'esims.listCompatibleDevices', 'esims.getProfile', 'orderItems.get', 'orderItems.cancel',
            'airtime.lookupNumber', 'utilities.getBiller', 'giftCards.getGiftCard', 'hotels.getHotel', 'hotels.exportCatalog',
            'flights.getSeatMap', 'flights.listPlaces', 'orders.order',
            'orderItems.change', 'orderItems.listChanges', 'orderItems.listPartnerChanges', 'orderItems.getChange',
            'wallets.setDefault', 'countries.list', 'mobileNetworks.list', 'currencies.list',
            'orders.resendEmail', 'account.listServices', 'browserSessions.create', 'browserSessions.revoke', 'checkouts.get', 'checkouts.confirm', 'checkouts.fail'] as $name) {
            $this->assertContains($name, $methods, "{$name} is missing");
        }
    }
}
