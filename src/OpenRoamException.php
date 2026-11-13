<?php
// Hand-written, not generated (see overlay/): the error every failed call of the OpenRoam client throws.

declare(strict_types=1);

namespace OpenRoam;

use Psr\Http\Message\ResponseInterface;

/**
 * Thrown by every failed call. For an error answer from the API it carries the error body as the API sends it:
 * getStatus() (the HTTP status), getCode() (the number to branch on, for example 11002), getErrorName() (its machine
 * name, for example PRICE_ABOVE_LIMIT), getMessage(), getRequestId() (keep it for support) and getDetails() (per-field
 * problems). When no answer arrived at all (network error, timeout), getStatus() is null, getCode() is 0 and
 * getPrevious() has the original error.
 */
class OpenRoamException extends \RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $details
     */
    public function __construct(
        string $message = 'The OpenRoam request failed.',
        private readonly ?int $status = null,
        int $code = 0,
        private readonly ?string $errorName = null,
        private readonly ?string $requestId = null,
        private readonly array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** The HTTP status of the answer, or null when no answer arrived. */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** The machine name of the error, for example INVALID_REQUEST, or null when the answer had none. */
    public function getErrorName(): ?string
    {
        return $this->errorName;
    }

    /** The id of the request on the OpenRoam side: quote it when you contact support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Per-field problems, each as the API sends it, for example ['path' => 'items[0].quantity', 'reason' => '...'].
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @internal The error of an answer: its error body when it has one, else its status only. */
    public static function fromResponse(ResponseInterface $response, ?\Throwable $previous = null): self
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            $body = []; // an empty or non-JSON body (a proxy error page, say): report the status only
        }
        $details = isset($body['details']) && is_array($body['details']) ? array_values(array_filter($body['details'], 'is_array')) : [];
        return new self(
            isset($body['message']) && is_string($body['message']) ? $body['message'] : "The request failed with status {$status}.",
            $status,
            isset($body['code']) && is_int($body['code']) ? $body['code'] : 0,
            isset($body['name']) && is_string($body['name']) ? $body['name'] : null,
            isset($body['requestId']) && is_string($body['requestId']) ? $body['requestId'] : null,
            $details,
            $previous
        );
    }
}
