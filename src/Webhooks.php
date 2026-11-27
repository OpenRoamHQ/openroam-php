<?php
// Hand-written, not generated (see overlay/): verify the signature of a webhook from OpenRoam.

namespace OpenRoam;

class Webhooks
{
    public const PRODUCTION_URL = 'https://api.openroam.com';
    public const SANDBOX_URL = 'https://api.sandbox.openroam.com';

    /**
     * Throws WebhookSignatureException unless the request is validly signed and recent.
     * $timestamp, $eventId and $signature are the X-OpenRoam-Timestamp, X-OpenRoam-Event-Id and X-OpenRoam-Signature
     * headers; $rawBody is the request body exactly as received.
     */
    public static function verify(
        string $secret,
        string $timestamp,
        string $eventId,
        string $signature,
        string $rawBody,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): void {
        // Unicode whitespace (e.g. U+2003), not only ASCII trim().
        if ($secret === '' || preg_match('/^\s*$/u', $secret) === 1) {
            throw new WebhookSignatureException('The webhook secret must not be empty.');
        }
        if (!is_numeric($timestamp)) {
            throw new WebhookSignatureException('Invalid X-OpenRoam-Timestamp.');
        }
        if (abs(($now ?? time()) - (float) $timestamp) > $toleranceSeconds) {
            throw new WebhookSignatureException('The timestamp is outside the tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $rawBody, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new WebhookSignatureException('Signature mismatch.');
        }
    }
}
