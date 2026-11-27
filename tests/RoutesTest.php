<?php

declare(strict_types=1);

namespace OpenRoam\Test;

use OpenRoam\ObjectSerializer;
use OpenRoam\OpenRoam;
use OpenRoam\OpenRoamException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Calls every method of the client once, driven by routes.json (written from the API description by scripts/php-client.cjs),
 * and checks the request it makes: verb, path with the ids, query parameters, flat body, X-Partner-Key and Idempotency-Key.
 */
class RoutesTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @return array<int, object> */
    private static function routes(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/routes.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: object}> */
    public static function allRoutes(): array
    {
        $cases = [];
        foreach (self::routes() as $route) {
            $cases["{$route->method} makes {$route->verb} {$route->route}"] = [$route];
        }
        return $cases;
    }

    /** @return array<string, array{0: object}> */
    public static function idempotentRoutes(): array
    {
        return array_filter(self::allRoutes(), fn (array $case) => $case[0]->idempotent);
    }

    /** @return array<string, array{0: object}> */
    public static function routesWithABody(): array
    {
        return array_filter(self::allRoutes(), fn (array $case) => $case[0]->body);
    }

    /** The routes whose body has a generated model; a body with no member (checkouts.fail) is an array. @return array<string, array{0: object}> */
    public static function routesWithABodyModel(): array
    {
        return array_filter(self::allRoutes(), fn (array $case) => $case[0]->body && $case[0]->bodyModel !== null);
    }

    /** Calls $method (for example "orderItems.getChange") once and returns the one request it made. */
    private function record(string $method, array $arguments): RequestInterface
    {
        $this->history = [];
        $handler = Middleware::history($this->history)(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{}')]));
        $owner = new OpenRoam(['apiKey' => 'key', 'baseUrl' => 'http://127.0.0.1:9', 'handler' => $handler]);
        $parts = explode('.', $method);
        $name = array_pop($parts);
        foreach ($parts as $part) {
            $owner = $owner->{$part};
        }
        try {
            $owner->{$name}(...$arguments);
        } catch (OpenRoamException $error) {
            // The answer is an empty object, which a list answer cannot be read as: this test is about the request.
            $this->assertSame(200, $error->getStatus(), $error->getMessage());
        }
        $this->assertCount(1, $this->history, 'exactly one request');
        return $this->history[0]['request'];
    }

    private static function ids(object $route): array
    {
        return array_map(fn (string $name) => "{$name}-value", $route->pathNames);
    }

    /** The sample body as a PHP array, the way a caller writes it. */
    private static function bodyArray(object $route): array
    {
        return json_decode(json_encode($route->bodySample), true);
    }

    private static function withoutNulls(mixed $value): mixed
    {
        if ($value === null) {
            return 'sample';
        }
        if (is_array($value)) {
            return array_map([self::class, 'withoutNulls'], $value);
        }
        if (is_object($value)) {
            return (object) array_map([self::class, 'withoutNulls'], get_object_vars($value));
        }
        return $value;
    }

    private static function sample(string $kind): mixed
    {
        return match ($kind) {
            'number' => 2,
            'boolean' => true,
            'date' => new \DateTimeImmutable('2026-01-02T03:04:05Z'),
            'array' => ['a', 'b'],
            default => 'sample',
        };
    }

    public function testTheRouteTableCoversAll73RoutesEachWithADifferentMethod(): void
    {
        $methods = array_map(fn (object $route) => $route->method, self::routes());
        $this->assertCount(73, $methods);
        $this->assertCount(73, array_unique($methods));
    }

    /** @dataProvider allRoutes */
    public function testTheMethodMakesItsRequest(object $route): void
    {
        $arguments = self::ids($route);
        $query = [];
        foreach ($route->query as $q) {
            $query[$q->name] = self::sample($q->kind);
        }
        if ($route->body) {
            $arguments[] = self::bodyArray($route);
        } elseif ($route->query !== []) {
            $arguments[] = $query;
        }
        $request = $this->record($route->method, $arguments);

        $this->assertSame($route->verb, $request->getMethod());
        $path = $route->route;
        foreach ($route->pathNames as $i => $name) {
            $path = str_replace('{' . $name . '}', rawurlencode(self::ids($route)[$i]), $path);
        }
        $this->assertSame($path, $request->getUri()->getPath());
        $this->assertSame('key', $request->getHeaderLine('X-Partner-Key'));

        $sent = Query::parse($request->getUri()->getQuery());
        foreach ($route->query as $q) {
            $this->assertArrayHasKey($q->name, $sent, "query parameter {$q->name} is sent");
            $expected = match ($q->kind) {
                'number' => '2',
                'boolean' => 'true',
                'array' => ['a', 'b'],
                'date' => null,
                default => 'sample',
            };
            if ($expected === null) {
                $this->assertSame(strtotime('2026-01-02T03:04:05Z'), strtotime($sent[$q->name]), $q->name);
            } else {
                $this->assertSame($expected, $sent[$q->name], $q->name);
            }
        }
        $this->assertCount(count($route->query), $sent, 'no other query parameter');

        if ($route->body) {
            // The body itself is sent, flat: its own top-level fields, not wrapped in an object named after its type.
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $expectedKeys = array_keys(self::bodyArray($route));
            $sentKeys = array_keys($body);
            sort($expectedKeys);
            sort($sentKeys);
            $this->assertSame($expectedKeys, $sentKeys);
            $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        } else {
            $this->assertSame('', (string) $request->getBody());
        }

        $this->assertSame($route->idempotent, $request->hasHeader('Idempotency-Key'));
        if ($route->idempotent) {
            $this->assertMatchesRegularExpression(self::UUID_V4, $request->getHeaderLine('Idempotency-Key'));
        }
    }

    /** @dataProvider idempotentRoutes */
    public function testTheMethodSendsYourOwnIdempotencyKey(object $route): void
    {
        $arguments = [...self::ids($route), ...($route->body ? [self::bodyArray($route)] : []), ['idempotencyKey' => 'my-key-42']];
        $request = $this->record($route->method, $arguments);
        $this->assertSame('my-key-42', $request->getHeaderLine('Idempotency-Key'));
    }

    /** @dataProvider routesWithABodyModel */
    public function testTheMethodAcceptsTheGeneratedModel(object $route): void
    {
        $class = '\\OpenRoam\\Model\\' . $route->bodyModel;
        $this->assertTrue(class_exists($class), "{$class} exists");
        // The sample leaves a value it cannot make up as null; a model getter typed `string` refuses that, so it gets a string.
        $model = ObjectSerializer::deserialize(self::withoutNulls($route->bodySample), $class);
        $request = $this->record($route->method, [...self::ids($route), $model]);
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys(self::bodyArray($route)) as $key) {
            $this->assertArrayHasKey($key, $body, "body field {$key} is sent at the top level");
        }
    }
}
