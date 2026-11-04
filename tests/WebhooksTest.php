<?php

namespace OpenRoam\Test;

use OpenRoam\WebhookSignatureException;
use OpenRoam\Webhooks;
use PHPUnit\Framework\TestCase;

class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const TS = '1770000000';
    private const ID = '3db22f3e-8a20-4e64-9f89-5ab49ef39f4d';
    private const SIGNATURE = 'bb4f9bc4366d7f728b5e08c0f7f40749dc487166c30fb6c307bc374c77b8e320';
    private const BODY = '{"eventId":"3db22f3e-8a20-4e64-9f89-5ab49ef39f4d","eventType":"partner.balance.low"}';

    public function testAValidRequestVerifies(): void
    {
        Webhooks::verify(self::SECRET, self::TS, self::ID, self::SIGNATURE, self::BODY, 300, 1770000100);
        $this->addToAssertionCount(1);
    }

    public function testAnUppercaseSignatureIsAccepted(): void
    {
        Webhooks::verify(self::SECRET, self::TS, self::ID, strtoupper(self::SIGNATURE), self::BODY, 300, 1770000100);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider refused */
    public function testARefusedRequest(string $secret, string $body, int $now): void
    {
        $this->expectException(WebhookSignatureException::class);
        Webhooks::verify($secret, self::TS, self::ID, self::SIGNATURE, $body, 300, $now);
    }

    public static function refused(): array
    {
        return [
            'changed body' => [self::SECRET, self::BODY . ' ', 1770000100],
            'wrong secret' => ['other', self::BODY, 1770000100],
            'too old' => [self::SECRET, self::BODY, 1770001000],
        ];
    }

    public function testTheReadmeSetupWorks(): void
    {
        $config = \OpenRoam\Configuration::getDefaultConfiguration()
            ->setApiKey('X-Partner-Key', 'key')
            ->setHost(Webhooks::SANDBOX_URL);
        $esims = new \OpenRoam\Api\EsimsApi(new \GuzzleHttp\Client(), $config);
        $this->assertTrue(method_exists($esims, 'esimsSearch'));
    }
}
