<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="brand/lockup-color-on-dark.svg" />
    <img src="brand/lockup-ink.svg" alt="OpenRoam" width="280" />
  </picture>
</p>

# OpenRoam SDK for PHP

Official client for the OpenRoam Partner API: eSIMs, airtime, utility payments, gift cards, hotels and flights.
PHP 8.1+.

## Install

```bash
composer require openroam/sdk:0.1.0-rc.7
```

> This is a **release candidate**: install it by its exact version, as above; a plain install does not pick it up. Before 1.0 the API can change between minor versions; every change is listed in the [changelog](https://openroamhq.github.io/openroam-sdks/changelog/).

## Environments

Partners use **Sandbox** (`https://api.sandbox.openroam.com`) or **Production** (`https://api.openroam.com`).
Default is Production. Pass `'environment' => 'sandbox'` for Sandbox. An explicit `baseUrl` is an unnamed override only —
not a third environment. The SDK sends your key as `X-Partner-Key`; a key works only on the environment that issued it.

## Quick start

```php
<?php
require 'vendor/autoload.php';

use OpenRoam\OpenRoam;

$openroam = new OpenRoam('your-key'); // or new OpenRoam() with OPENROAM_API_KEY set

$found = $openroam->esims->search(['countryCode' => 'JP']);
$quote = $openroam->esims->quote(['currency' => 'USD', 'items' => $items]);
$order = $openroam->esims->order(['currency' => 'USD', 'items' => $items]);

$openroam->esims->getUsage($iccid);
$openroam->hotels->search([/* ... */]);
```

Every product is a property of the client: `esims`, `airtime`, `utilities`, `giftCards`, `hotels`, `flights`, plus `orders`, `orderItems`,
`wallets`, `account`, `browserSessions`, `checkouts`, `currencies`, `countries` and `mobileNetworks`. Buying always reads `search`, `quote`, `order`; `get` fetches one thing you own,
`list...` returns many, everything else starts with a verb. A path id is the first argument; a body is passed as it is, as an array or as
the generated model (`OpenRoam\Model\EsimOrderRequest`, ...); query parameters are one array with the API's names; options come last.
Answers are the generated models, read with getters: `$found->getItems()`.

`checkouts->confirm` and `checkouts->fail` answer a checkout of a site that takes payment on your own page. Send them from your server
with a client whose `apiKey` is that site's confirmation key (`orck_...`): a partner API key gets `403`. No signature headers are sent.

Options when you need them:

```php
$openroam = new OpenRoam([
    'apiKey' => 'key',
    'environment' => 'sandbox', // default 'production'
    'maxRetries' => 3,          // default 0: no retries
    // 'baseUrl' => 'https://...' // optional unnamed override (not a named environment)
    // 'handler' => a Guzzle handler, for example a HandlerStack with your own middleware
]);
```

A call takes `['timeout' => 10]` (seconds) as its last argument to give up waiting.

## Idempotency

An idempotency key means: if the same request arrives twice, OpenRoam makes one order, not two. `order()` creates a key for you on every call.
To stay safe even if *your own* program crashes and asks again, pass a UUID generated once and persisted for that purchase, for example your order id if it is a UUID:

```php
$openroam->esims->order($body, ['idempotencyKey' => $myOrderId]);
```

## Errors

A failed call throws an `OpenRoamException` (a `RuntimeException`) with the error body of the API: `getStatus()`, `getCode()` (branch on this),
`getErrorName()`, `getMessage()`, `getRequestId()` (keep it for support) and `getDetails()`. When no answer arrived (network error), `getStatus()` is
null and `getPrevious()` has the original error.

```php
use OpenRoam\OpenRoamException;

try {
    $openroam->esims->order($body);
} catch (OpenRoamException $error) {
    if ($error->getCode() === 11002) { /* the price rose above your maxPrice */ }
    else throw $error;
}
```

## Lists and pages

Pagination depends on the method. `esims->search` accepts `page` and `limit` and returns `items` and `pagination`, while `orders->list` returns `orders` and `pagination`.
`esims->listCatalog` accepts `cursor` and `limit` and returns `items` and `nextCursor`; pass `getNextCursor()` as `cursor` to fetch the next page.
Some lists, such as `esims->listCompatibleDevices`, return an unpaged array.

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

## Retries

Retries are off by default. Set `maxRetries` to retry network errors, `429`, `502/503/504` and "still running" (`12002`) safely:
a `POST` is sent again only with its `Idempotency-Key`, the same key and the same body, and `order()` always has one.

```php
$openroam = new OpenRoam(['apiKey' => $apiKey, 'maxRetries' => 3]);
```

The full behaviour: [Retries and idempotency](https://openroamhq.github.io/openroam-sdks/retries/). `Retry::middleware()`, `Retry::client()` and
`Retry::newIdempotencyKey()` are also available if you build your own requests.

## Good to know

- **Amounts** are `{ amount, currency }`. `price` is what OpenRoam charges you, `value` what your customer receives. Do not do arithmetic on amounts (JSON numbers are floating point in this language).
- API reference and partner portal: https://openroam.com. Support: support@openroam.com.

The client is generated from the OpenRoam Partner API description, so it always matches the API; `Webhooks`, `Retry` and the errors are hand-written. MIT licensed.
