<?php
// Hand-written, not generated (see overlay/): the opt-in retry layer described in docs/RETRIES.md.

namespace OpenRoam;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retries a request that is safe to repeat (GET/HEAD, or any request with an Idempotency-Key) after a network error,
 * a 429, a 502/503/504 or a 12002 IDEMPOTENCY_KEY_IN_PROGRESS answer, resending it byte for byte. A 429 is retried for
 * every request. After the last allowed attempt the last response (or error) is returned unchanged.
 *
 * Options (all optional; delays are in milliseconds):
 * - maxRetries (3): retries after the first attempt for network errors, 429 and 502/503/504
 * - baseDelay (500): first backoff step
 * - maxDelay (30000): upper bound of any wait, including Retry-After
 * - inProgressRetries (10): retries for 12002
 * - onRetry (null): callable(int $attempt, string $reason, int $delayMs), called before each wait
 * - sleep (null): callable(int $delayMs) that waits; for tests. By default a retry is sent with Guzzle's `delay` request
 *   option, so waiting never blocks other requests running in the same pool
 */
final class Retry
{
    private const DEFAULTS = [
        'maxRetries' => 3,
        'baseDelay' => 500,
        'maxDelay' => 30000,
        'inProgressRetries' => 10,
        'onRetry' => null,
        'sleep' => null,
    ];

    private const IN_PROGRESS = 12002;
    private const IN_PROGRESS_DEFAULT_WAIT = 2000;

    /** A Guzzle client with the retry middleware: pass it to any Api class, e.g. new EsimsApi(Retry::client(), $config). */
    public static function client(array $options = [], array $guzzleConfig = []): Client
    {
        $handler = $guzzleConfig['handler'] ?? null;
        $stack = $handler instanceof HandlerStack ? $handler : HandlerStack::create($handler);
        $stack->push(self::middleware($options), 'openroam_retry');
        $guzzleConfig['handler'] = $stack;
        return new Client($guzzleConfig);
    }

    /** A Guzzle middleware; push it last on a HandlerStack so that it sees every raw response before http_errors. */
    public static function middleware(array $options = []): callable
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown retry option(s): ' . implode(', ', $unknown));
        }
        $o = array_merge(self::DEFAULTS, $options);
        return static function (callable $handler) use ($o): callable {
            return static function (RequestInterface $request, array $requestOptions) use ($handler, $o): PromiseInterface {
                if (!$request->getBody()->isSeekable()) {
                    return $handler($request, $requestOptions);
                }
                $retries = 0;
                $inProgressRetries = 0;
                $repeatable = in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
                    || trim($request->getHeaderLine('Idempotency-Key')) !== '';

                $send = static function (array $sendOptions) use (&$send, &$retries, &$inProgressRetries, $handler, $request, $requestOptions, $repeatable, $o): PromiseInterface {
                    $again = static function (string $reason, int $delayMs) use (&$send, &$retries, &$inProgressRetries, $request, $requestOptions, $o): PromiseInterface {
                        if ($o['onRetry'] !== null) {
                            ($o['onRetry'])($retries + $inProgressRetries, $reason, $delayMs);
                        }
                        $nextOptions = $requestOptions;
                        if ($o['sleep'] !== null) {
                            ($o['sleep'])($delayMs);
                        } else {
                            $nextOptions['delay'] = $delayMs; // the transport waits; other requests keep running
                        }
                        $body = $request->getBody();
                        if ($body->isSeekable()) {
                            $body->rewind();
                        }
                        return $send($nextOptions);
                    };

                    return $handler($request, $sendOptions)->then(
                        static function (ResponseInterface $response) use (&$retries, &$inProgressRetries, $repeatable, $o, $again) {
                            $status = $response->getStatusCode();
                            $retryAfter = self::retryAfter($response);

                            if ($repeatable && ($status === 202 || $status === 409)) {
                                [$response, $code] = self::errorCode($response);
                                if ($code === self::IN_PROGRESS) {
                                    if ($inProgressRetries >= $o['inProgressRetries']) {
                                        return $response;
                                    }
                                    $inProgressRetries++;
                                    $delay = min($o['maxDelay'], $retryAfter ?? self::IN_PROGRESS_DEFAULT_WAIT);
                                    return $again('in progress (12002)', $delay);
                                }
                                return $response;
                            }

                            $retryable = $status === 429 || ($repeatable && in_array($status, [502, 503, 504], true));
                            if (!$retryable || $retries >= $o['maxRetries']) {
                                return $response;
                            }
                            $retries++;
                            return $again('HTTP ' . $status, self::backoff($retries, $retryAfter, $o));
                        },
                        static function ($reason) use (&$retries, $repeatable, $o, $again) {
                            $network = $reason instanceof ConnectException
                                || ($reason instanceof RequestException && !$reason->hasResponse());
                            if (!$network || !$repeatable || $retries >= $o['maxRetries']) {
                                return Create::rejectionFor($reason);
                            }
                            $retries++;
                            return $again('network error', self::backoff($retries, null, $o));
                        }
                    );
                };

                return $send($requestOptions);
            };
        };
    }

    /** A fresh random UUID (version 4) for the Idempotency-Key of one purchase. Keep it for every retry of that purchase. */
    public static function newIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Full jitter for retry $n (1-based), never below Retry-After, capped at maxDelay. */
    private static function backoff(int $n, ?int $retryAfter, array $o): int
    {
        $ceiling = (int) min($o['maxDelay'], $o['baseDelay'] * (2 ** ($n - 1)));
        $delay = random_int(0, max(0, $ceiling));
        if ($retryAfter !== null) {
            $delay = max($delay, $retryAfter);
        }
        return (int) min($delay, $o['maxDelay']);
    }

    /** Retry-After in milliseconds (whole seconds or an HTTP date), or null when absent, invalid or negative. */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }
        $ms = (int) round(((float) $date->format('U.u') - microtime(true)) * 1000);
        return $ms < 0 ? null : $ms;
    }

    /**
     * The body's "code", read without consuming the body: a seekable stream is rewound, any other is replaced by a copy.
     * @return array{0: ResponseInterface, 1: ?int}
     */
    private static function errorCode(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $contents = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        } else {
            $response = $response->withBody(Utils::streamFor($contents));
        }
        $json = json_decode($contents, true);
        $code = is_array($json) && isset($json['code']) && is_numeric($json['code']) ? (int) $json['code'] : null;
        return [$response, $code];
    }
}
