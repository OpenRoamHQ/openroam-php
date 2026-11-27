<?php
// Hand-written, not generated (see overlay/). The parts of the `OpenRoam` client that are not one method per API route:
// options, sending a request and turning a failed one into an OpenRoamException. The generated resources
// (scripts/php-client.cjs) call it.

declare(strict_types=1);

namespace OpenRoam\Client;

use OpenRoam\Configuration;
use OpenRoam\Model\AnyOfInterface;
use OpenRoam\Model\ModelInterface;
use OpenRoam\Model\OneOfInterface;
use OpenRoam\ObjectSerializer;
use OpenRoam\OpenRoamException;
use OpenRoam\Retry;
use OpenRoam\Webhooks;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal Made by the OpenRoam client; use the client, not this class.
 */
final class Transport
{
    public const ENVIRONMENTS = ['production' => Webhooks::PRODUCTION_URL, 'sandbox' => Webhooks::SANDBOX_URL];

    private const OPTIONS = ['apiKey', 'environment', 'baseUrl', 'maxRetries', 'handler'];
    private const IN_PROGRESS = 12002;

    private Configuration $config;
    private ClientInterface $http;
    /** @var array<string, object> */
    private array $apis = [];

    /**
     * @param string|array{apiKey?: string, environment?: string, baseUrl?: string, maxRetries?: int, handler?: callable}|null $options
     */
    public function __construct(string|array|null $options = null)
    {
        $settings = is_string($options) ? ['apiKey' => $options] : ($options ?? []);
        $unknown = array_diff(array_keys($settings), self::OPTIONS);
        if ($unknown !== []) {
            throw new OpenRoamException('Unknown option(s): ' . implode(', ', $unknown) . '. Use ' . implode(', ', self::OPTIONS) . '.');
        }

        $apiKey = $settings['apiKey'] ?? self::environmentVariable('OPENROAM_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new OpenRoamException("Missing API key. Pass it as new OpenRoam('your-key') or set OPENROAM_API_KEY.");
        }

        $environment = $settings['environment'] ?? 'production';
        if (!is_string($environment) || !array_key_exists($environment, self::ENVIRONMENTS)) {
            $shown = is_string($environment) ? $environment : get_debug_type($environment);
            throw new OpenRoamException("Unknown environment \"{$shown}\". Use 'production' or 'sandbox', or pass baseUrl.");
        }
        $baseUrl = $settings['baseUrl'] ?? self::ENVIRONMENTS[$environment];
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new OpenRoamException('baseUrl must be a URL, for example https://api.sandbox.openroam.com.');
        }

        $maxRetries = $settings['maxRetries'] ?? 0;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new OpenRoamException('maxRetries must be a whole number, 0 or more.');
        }
        $handler = $settings['handler'] ?? null;
        if ($handler !== null && !is_callable($handler)) {
            throw new OpenRoamException('handler must be a Guzzle handler (a callable).');
        }

        $this->config = (new Configuration())
            ->setApiKey('X-Partner-Key', $apiKey)
            ->setHost(rtrim($baseUrl, '/'));

        // A stack of the caller's is copied: the retry layer of this client must not reach other clients that share it.
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);
        if ($maxRetries > 0) {
            $stack->push(Retry::middleware(['maxRetries' => $maxRetries]), 'openroam_retry');
        }
        // Every answer comes back as a response; call() decides what is an error.
        $this->http = new Client(['handler' => $stack, RequestOptions::HTTP_ERRORS => false]);
    }

    /**
     * Sends one API call and returns its answer, read as the route's model. Whatever fails becomes an OpenRoamException.
     *
     * $route: api (the generated Api class), operation (its operationId), path (argument => id), query (API name =>
     * [argument, type]), body (argument), bodyType (its model), idempotencyKey (argument) and returns (the type of the
     * answer; absent: none).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $options
     * @param array<string, mixed> $query
     */
    public function call(array $route, array $options = [], array $query = [], mixed $body = null): mixed
    {
        $request = $this->request($route, $options, $query, $body);
        $sendOptions = isset($options['timeout']) ? [RequestOptions::TIMEOUT => $options['timeout']] : [];

        try {
            $response = $this->http->send($request, $sendOptions);
        } catch (GuzzleException $error) {
            $answer = method_exists($error, 'getResponse') ? $error->getResponse() : null;
            if ($answer instanceof ResponseInterface) {
                throw OpenRoamException::fromResponse($answer, $error);
            }
            throw new OpenRoamException('The request did not get an answer: ' . $error->getMessage(), null, 0, null, null, [], $error);
        }

        $status = $response->getStatusCode();
        $code = null;
        if ($status === 202) {
            [$response, $code] = self::bodyCode($response);
        }
        if ($status < 200 || $status > 299 || ($status === 202 && $code === self::IN_PROGRESS)) {
            // A 202 whose body says 12002 (IDEMPOTENCY_KEY_IN_PROGRESS) means "still running": the order is not done yet.
            throw OpenRoamException::fromResponse($response);
        }
        return self::read($response, $route['returns'] ?? null);
    }

    /**
     * The HTTP request, built by the generated `<operation>Request()` method.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $options
     * @param array<string, mixed> $query
     */
    private function request(array $route, array $options, array $query, mixed $body): RequestInterface
    {
        $allowed = isset($route['idempotencyKey']) ? ['idempotencyKey', 'timeout'] : ['timeout'];
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new OpenRoamException('Unknown call option(s): ' . implode(', ', $unknown) . '. This method takes ' . implode(', ', $allowed) . '.');
        }
        if (isset($options['timeout']) && ((!is_int($options['timeout']) && !is_float($options['timeout'])) || $options['timeout'] <= 0)) {
            throw new OpenRoamException('timeout must be a number of seconds above 0.');
        }

        $arguments = [];
        // Only the route's own query parameters are read, and the path ids are set after them: a value in the query array
        // can never replace a positional id.
        // A parameter the caller left out is passed as null, so it is not sent and the API applies its own default.
        foreach ($route['query'] ?? [] as $name => [$argument, $type]) {
            $arguments[$argument] = isset($query[$name]) ? self::queryValue($query[$name], $type, $name) : null;
        }
        foreach ($route['path'] ?? [] as $argument => $id) {
            $arguments[$argument] = $id;
        }
        if (isset($route['idempotencyKey'])) {
            $key = $options['idempotencyKey'] ?? Retry::newIdempotencyKey();
            if (!is_string($key) || trim($key) === '') {
                throw new OpenRoamException('idempotencyKey must be a non-empty string, for example a UUID kept for this purchase.');
            }
            $arguments[$route['idempotencyKey']] = $key;
        }
        $json = null;
        if (isset($route['body'])) {
            if ($body === null || $body instanceof ModelInterface) {
                $arguments[$route['body']] = $body;
            } else {
                // An array is sent as it is, so a field the model does not know yet still reaches the API.
                $json = self::encode($body, $route['bodyType'] ?? null);
            }
        }

        try {
            $request = $this->api($route['api'])->{$route['operation'] . 'Request'}(...$arguments);
        } catch (\Throwable $error) {
            throw new OpenRoamException('The request could not be built: ' . $error->getMessage(), null, 0, null, null, [], $error);
        }
        if ($json !== null) {
            $request = $request->withBody(Utils::streamFor($json))->withHeader('Content-Type', 'application/json');
        }
        return $request;
    }

    private function api(string $class): object
    {
        return $this->apis[$class] ??= new $class($this->http, $this->config);
    }

    private static function queryValue(mixed $value, string $type, string $name): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($type === '\DateTime') {
            try {
                if ($value instanceof \DateTimeInterface) {
                    return \DateTime::createFromInterface($value);
                }
                if (is_string($value)) {
                    return new \DateTime($value);
                }
            } catch (\Exception $error) {
                throw new OpenRoamException("The query parameter {$name} is not a date: " . $error->getMessage(), null, 0, null, null, [], $error);
            }
        }
        if ($type === 'array' && is_array($value)) {
            return array_map(static fn ($item) => $item instanceof \BackedEnum ? $item->value : $item, $value);
        }
        return $value;
    }

    private static function encode(mixed $body, ?string $model): string
    {
        try {
            $data = self::sanitize($body, $model);
            return $data === [] ? '{}' : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $error) {
            throw new OpenRoamException('The body could not be written as JSON: ' . $error->getMessage(), null, 0, null, null, [], $error);
        }
    }

    /**
     * An array body made ready for JSON the way its model would be: a field the model declares as a date is written as
     * Y-m-d, a date-time in the SDK's format, at any depth. A field the model does not know is kept as it is.
     */
    private static function sanitize(mixed $value, ?string $type, ?string $format = null): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return ObjectSerializer::sanitizeForSerialization(\DateTime::createFromInterface($value), null, $format);
        }
        if (!is_array($value)) {
            return ObjectSerializer::sanitizeForSerialization($value, $type, $format);
        }
        if ($type !== null && str_ends_with($type, '[]')) {
            $item = substr($type, 0, -2);
            return array_map(static fn ($entry) => self::sanitize($entry, $item, $format), $value);
        }
        $class = $type === null ? null : self::modelOf($type, $value);
        if ($class === null) {
            return array_map(static fn ($entry) => self::sanitize($entry, null), $value);
        }
        $properties = array_flip($class::attributeMap());
        $types = $class::openAPITypes();
        $formats = $class::openAPIFormats();
        $out = [];
        foreach ($value as $key => $entry) {
            $property = $properties[$key] ?? null;
            $out[$key] = $property === null ? self::sanitize($entry, null) : self::sanitize($entry, $types[$property], $formats[$property] ?? null);
        }
        return (object) $out;
    }

    /**
     * The model class an array stands for: $type itself, or for a oneOf/anyOf the first member that knows every key of it.
     *
     * @param array<array-key, mixed> $value
     * @return class-string<ModelInterface>|null
     */
    private static function modelOf(string $type, array $value): ?string
    {
        $class = ltrim($type, '\\');
        if (!class_exists($class)) {
            return null;
        }
        $members = is_subclass_of($class, OneOfInterface::class) ? $class::getOneOfTypes()
            : (is_subclass_of($class, AnyOfInterface::class) ? $class::getAnyOfTypes() : null);
        if ($members === null) {
            return is_subclass_of($class, ModelInterface::class) ? $class : null;
        }
        $oneOf = is_subclass_of($class, OneOfInterface::class);
        $discriminator = $oneOf ? $class::getOneOfDiscriminator() : $class::getAnyOfDiscriminator();
        $mappings = $oneOf ? $class::getOneOfDiscriminatorMappings() : $class::getAnyOfDiscriminatorMappings();
        $tag = $discriminator === null ? null : ($value[$discriminator] ?? null);
        if ($tag instanceof \BackedEnum) {
            $tag = $tag->value;
        }
        if (is_string($tag) && isset($mappings[$tag])) {
            $member = ltrim($mappings[$tag], '\\');
            if (class_exists($member) && is_subclass_of($member, ModelInterface::class)) {
                return $member;
            }
        }
        foreach ($members as $member) {
            $member = ltrim($member, '\\');
            if (class_exists($member) && is_subclass_of($member, ModelInterface::class)
                && array_diff(array_keys($value), array_values($member::attributeMap())) === []) {
                return $member;
            }
        }
        return null;
    }

    /** The answer read as $type: a model, a list of models, or nothing for a route without a body. */
    private static function read(ResponseInterface $response, ?string $type): mixed
    {
        if ($type === null || $type === '') {
            return null;
        }
        $status = $response->getStatusCode();
        try {
            $content = (string) $response->getBody();
            $data = $type === 'string' ? $content : json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $result = ObjectSerializer::deserialize($data, $type, $response->getHeaders());
        } catch (\Throwable $error) {
            throw new OpenRoamException("The answer (status {$status}) could not be read: " . $error->getMessage(), $status, 0, null, null, [], $error);
        }
        $class = ltrim($type, '\\');
        $plainModel = !str_ends_with($type, '[]') && class_exists($class)
            && !is_subclass_of($class, OneOfInterface::class) && !is_subclass_of($class, AnyOfInterface::class);
        if ($plainModel && !$result instanceof $class) {
            throw new OpenRoamException("The answer (status {$status}) is not a {$class}.", $status);
        }
        if (str_ends_with($type, '[]') && !is_array($result)) {
            throw new OpenRoamException("The answer (status {$status}) is not a list.", $status);
        }
        return $result;
    }

    /**
     * The "code" of a JSON body, read without consuming the body: a seekable stream is rewound, any other is replaced by a copy.
     *
     * @return array{0: ResponseInterface, 1: ?int}
     */
    private static function bodyCode(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $contents = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        } else {
            $response = $response->withBody(Utils::streamFor($contents));
        }
        $json = json_decode($contents, true);
        return [$response, is_array($json) && isset($json['code']) && is_int($json['code']) ? $json['code'] : null];
    }

    private static function environmentVariable(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
