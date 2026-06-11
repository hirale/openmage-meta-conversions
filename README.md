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

## Install

Requires [`hirale/queue`](https://github.com/hirale/queue) `^3.0`
(pulled in automatically).

**Maho** (26.5+):

```bash
composer require hirale/openmage-meta-conversions
```

**OpenMage** (20.17+, PHP 8.3+) — one-time tweaks first; details in the
[hirale/queue README](https://github.com/hirale/queue#openmage-one-time-composer-adjustments):

```bash
composer config platform.php 8.3
composer config allow-plugins.hirale/magento-module-installer true
composer require hirale/magento-module-installer hirale/openmage-meta-conversions
```

## Usage

### Setup
1. This module requires the [hirale/queue](https://github.com/hirale/queue) module — configure its backend first (System > Configuration > Hirale > Queue).
2. Generate an access token. See [https://developers.facebook.com/docs/marketing-api/conversions-api/get-started](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started).
3. Go to system config `System > Configuration > Sales > Meta API > Conversions API`. Insert the parameters from step 1, save.

### Debug

Enable Debug Mode in system config, then check `var/log/meta_conversions.log`.
Each processed queue message logs two entries: the event batch (envelopes +
custom data — `user_data` is deliberately never written to logs, and PII is
already SHA-256 hashed before it even reaches the queue) and the Graph API
response.

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