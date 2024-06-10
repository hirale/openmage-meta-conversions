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

> [!NOTE]
> This module depends on [`openmage-redis-queue`](https://github.com/hirale/openmage-redis-queue). It has been added to composer requirements.

### Install with [Magento Composer Installer](https://github.com/Cotya/magento-composer-installer)

```bash
composer require hirale/openmage-meta-conversions
```

## Usage

### Setup
1. This module requires [openmage-redis-queue](https://github.com/hirale/openmage-redis-queue) module, please take a look before you install this module.
2. Generate an access token. See [https://developers.facebook.com/docs/marketing-api/conversions-api/get-started](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started).
3. Go to system config `System > Configuration > Sales > Meta API > Conversions API`. Insert the parameters from step 1, save.

### Debug

Enable Debug Mode in system config, then check your system logs.

```log
2024-06-10T18:28:24+00:00 DEBUG (7): FacebookAds\Object\ServerSide\Event Object
(
    [container:protected] => Array
        (
            [event_name] => PageView
            [event_time] => 1718044092
            [event_source_url] => https://example.com/customer/account/index/
            [opt_out] => 
            [event_id] => 666745bcdd76a
            [user_data] => FacebookAds\Object\ServerSide\UserData Object
                (
                    [container:protected] => Array
                        (
                            [emails] => Array
                                (
                                    [0] => ok@example.com
                                )

                            [phones] => Array
                                (
                                    [0] => 1234567894
                                )

                            [genders] => Array
                                (
                                    [0] => f
                                )

                            [last_names] => Array
                                (
                                    [0] => ok
                                )

                            [first_names] => Array
                                (
                                    [0] => ok
                                )

                            [cities] => Array
                                (
                                    [0] => ok
                                )

                            [states] => Array
                                (
                                    [0] => Alaska
                                )

                            [country_codes] => Array
                                (
                                    [0] => US
                                )

                            [zip_codes] => Array
                                (
                                    [0] => 10010
                                )

                            [client_ip_address] => 172.20.0.1
                            [client_user_agent] => Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36
                            [fbc] => 
                            [fbp] => fb.1.17984613648566.610809845
                            [subscription_id] => 
                            [fb_login_id] => 
                            [lead_id] => 
                            [f5first] => 
                            [f5last] => 
                            [fi] => 
                            [dobd] => 
                            [dobm] => 
                            [doby] => 
                            [madid] => 
                            [anon_id] => 
                            [ctwa_clid] => 
                            [page_id] => 
                        )

                )

            [custom_data] => 
            [data_processing_options] => 
            [data_processing_options_country] => 
            [data_processing_options_state] => 
            [action_source] => website
            [app_data] => 
            [advanced_measurement_table] => 
            [messaging_channel] => 
        )

)

2024-06-10T18:28:24+00:00 DEBUG (7): FacebookAds\Object\ServerSide\EventResponse Object
(
    [container:protected] => Array
        (
            [events_received] => 1
            [messages] => Array
                (
                )

            [fbtrace_id] => AkuJqnm2pr421jM7d89SRqa
            [custom_endpoint_responses] => 
        )

)
```

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.