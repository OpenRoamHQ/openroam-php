# OpenRoam SDK for PHP

Official client for the OpenRoam Partner API: eSIMs, airtime, utility payments, gift cards, hotels and flights.
PHP 8.1+.

## Install

```bash
composer require openroam/sdk:0.1.0-rc.2
```

> This is a **release candidate**: install it by its exact version, as above; a plain install does not pick it up. Before 1.0 the API can change between minor versions; every change is listed in the [changelog](https://github.com/OpenRoamHQ/openroam-sdks/blob/main/CHANGELOG.md).

## Quick start

```php
<?php
require 'vendor/autoload.php';

$config = OpenRoam\Configuration::getDefaultConfiguration()
    ->setApiKey('X-Partner-Key', getenv('OPENROAM_API_KEY'))
    ->setHost(OpenRoam\Webhooks::SANDBOX_URL); // or PRODUCTION_URL

$esims = new OpenRoam\Api\EsimsApi(new GuzzleHttp\Client(), $config);
$result = $esims->esimsSearch(country_code: 'JP');
```

Every product has its own class (`AirtimeApi`, `EsimsApi`, `GiftCardsApi`, `HotelsApi`, `FlightsApi`, `UtilityApi`, `OrdersApi`, `WalletsApi`...) and each follows search, quote, order.

## Webhooks

```php
use OpenRoam\Webhooks;
use OpenRoam\WebhookSignatureException;

Webhooks::verify(
    secret: getenv('OPENROAM_WEBHOOK_SECRET'),
    timestamp: $_SERVER['HTTP_X_OPENROAM_TIMESTAMP'],
    eventId: $_SERVER['HTTP_X_OPENROAM_EVENT_ID'],
    signature: $_SERVER['HTTP_X_OPENROAM_SIGNATURE'],
    rawBody: file_get_contents('php://input'), // exactly as received
); // throws WebhookSignatureException when invalid or older than 5 minutes
```

## Good to know

- **Orders need an `Idempotency-Key`** (a new UUID per purchase). After a timeout or a `5xx`, retry with the same key and the same body: it never charges twice.
- **Amounts** are `{ amount, currency }`. `price` is what OpenRoam charges you, `value` what your customer receives.
- **Errors** carry `code`, `name`, `message`, `requestId` and `details`; quote `requestId` when you contact support.
- Full API documentation: https://openroam.com (partner portal). Support: support@openroam.com.

This SDK is generated from the OpenRoam Partner API description; `webhooks` is hand-written. MIT licensed.
