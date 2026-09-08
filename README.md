# Hirale Meta Conversions API Module

A module for integrating [Meta Conversions API](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started), sending events from server side.

For duplicate events, you can consult this page [https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events](https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events)

If you are using javascript to send pixel events, You can get event_id like this.

``` php
Mage::helper('metaconversions')->getEventId();
```
## Supported Events

 - `AddToCart`
 - `AddToWishlist`
 - `CompleteRegistration`
 - `InitiateCheckout`
 - `Purchase`
 - `Search`
 - `ViewContent`
 - `PageView`
 - `ViewCart`

You can check more events in the [events section](https://developers.facebook.com/docs/meta-pixel/reference#standard-events).

## Queue backend

Events are never posted from the request that generated them: the observer
hands one message per request to a queue and a worker uploads the batch. There
is no shared queue package any more — the module picks a backend at runtime, in
this order:

| Platform | Backend | Package to install |
| --- | --- | --- |
| Maho with the core `Maho_Queue` module | `\Maho\Queue\QueueManager` | none — it ships with the platform |
| OpenMage | `\Hirale\Queue\Bus` | [`hirale/queue`](https://github.com/hirale/queue) `^3.0` |
| Neither | — | events are not queued; the storefront is unaffected |

`Maho_Queue` wins whenever it is present and enabled, even on a store that also
has `hirale/queue` installed.

Messages ride the `analytics` queue on both platforms. On Maho, `config.xml`
routes that queue to the catch-all `slow` pool, so a CAPI upload — one outbound
HTTP call that can block on Meta — never competes with the resident `fast` pool
that carries order mail. A host can retarget it from its own `config.xml` or
`local.xml`.

> **3.0.0 is a breaking change.** `hirale/queue` moved from `require` to
> `suggest`. OpenMage installs that upgrade from 2.x must require it
> explicitly, or events stop being queued. Nothing else changes: the same
> events, config paths and `analytics` queue name as before.

## Install

**Maho** (26.5+, with core `Maho_Queue`):

```bash
composer require hirale/openmage-meta-conversions
composer dump-autoload
```

`composer dump-autoload` is required: it compiles the
`#[\Maho\Config\MessageHandler]` attribute into
`vendor/composer/maho_attributes.php`. Without it the message has no registered
handler, and the queue refuses to decode it.

**OpenMage** (20.17+, PHP 8.3+) — one-time tweaks first; details in the
[hirale/queue README](https://github.com/hirale/queue#openmage-one-time-composer-adjustments):

```bash
composer config platform.php 8.3
composer config allow-plugins.hirale/magento-module-installer true
composer require hirale/magento-module-installer hirale/queue hirale/openmage-meta-conversions
```

## Usage

### Setup
1. Make sure a queue backend is configured and its worker is running (see [Queue backend](#queue-backend)) — on OpenMage that is `System > Configuration > Hirale > Queue`, on Maho the core queue needs no setup beyond a running worker.
2. Generate an access token. See [https://developers.facebook.com/docs/marketing-api/conversions-api/get-started](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started).
3. Go to system config `System > Configuration > Sales > Meta API > Conversions API`. Insert the parameters from step 1, save.

### Event reporting rules

- Route events (`PageView`, `Purchase`, `InitiateCheckout`, `ViewCart`,
  `ViewContent`, `Search`) are reported only from a rendered `200` HTML
  response. A redirect, a JSON endpoint or an error page reports nothing — an
  empty cart bounced back from checkout is not an `InitiateCheckout`.
- `Purchase` is reported once per order. A reloaded success page returns a
  redirect, which the rule above already stops; on Maho a mark on the checkout
  session backs that up. **On OpenMage that mark is not persisted** — the
  platform closes the session before `core_app_run_after` dispatches — so there
  the redirect rule is the only thing preventing a duplicate. Meta cannot
  absorb such a duplicate on its own: every dispatch mints its own `event_id`,
  and deduplication is keyed on (`event_name`, `event_id`).
- An observer that fails while building a payload logs and gives up. It never
  interrupts the action it is measuring: a cart save, a registration.
- A store with no access token or pixel id logs one line per dropped batch,
  whether or not debug mode is on.

### Debug

Enable Debug Mode in system config, then check `var/log/meta_conversions.log`.
Each processed queue message logs two entries: the event batch (envelopes +
custom data — `user_data` is deliberately never written to logs, and PII is
already SHA-256 hashed before it even reaches the queue) and the Graph API
response.

Permanent errors fail the queue job immediately and show up in the queue's
failure list; transient errors retry with backoff. Permanent means a replay
cannot succeed: an invalid or revoked access token, a missing permission, a
duplicate post, or any other `4xx` from the Graph API. Rate limiting (Meta
answers `400` for it), `5xx` and network failures retry.

```log
2026-06-11T10:00:00+00:00 DEBUG (7): Array
(
    [store_id] => 1
    [events] => Array
        (
            [0] => Array
                (
                    [event] => Array
                        (
                            [event_time] => 1718044092
                            [event_source_url] => https://example.com/some-product.html
                            [action_source] => website
                            [event_id] => 666745bcdd76a
                            [event_name] => ViewContent
                        )

                    [custom_data] => Array
                        (
                            [currency] => USD
                            [content_type] => product
                            [content_ids] => Array ( [0] => SKU-9 )
                        )

                )

            [1] => Array
                (
                    [event] => Array
                        (
                            [event_name] => PageView
                        )

                    [custom_data] => 
                )

        )

)

2026-06-11T10:00:00+00:00 DEBUG (7): FacebookAds\Object\ServerSide\EventResponse Object
(
    [container:protected] => Array
        (
            [events_received] => 2
            [messages] => Array ( )
            [fbtrace_id] => AkuJqnm2pr421jM7d89SRqa
        )

)
```

## Upgrading

- The access token is now stored encrypted (`adminhtml/system_config_backend_encrypted`).
  After upgrading, re-enter and save the token once in system config.
- The queue message schema changed (one message now carries all events of a
  request, and PII is hashed before enqueueing). Let the queue worker drain
  pending metaconversions messages before deploying the upgrade; messages
  enqueued by the old version cannot be processed by the new handler.

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.