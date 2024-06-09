# Hirale Meta Conversions API Module

A module for integrating [Meta Conversions API](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started), sending events from server side.

For duplicate events, you can consult this page [https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events](https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events)

If you are using javascript to send pixel events, You can get event_id like this.

``` php
Mage::helper('metaconversions')->getEventId();
```

## Install

### Install with [Magento Composer Installer](https://github.com/Cotya/magento-composer-installer)

```bash
composer require hirale/openmage-meta-conversions
```

## Usage

### Setup
1. This module requires [openmage-redis-queue](https://github.com/hirale/openmage-redis-queue) module, please take a look before you install this module.
2. Generate an access token. See [https://developers.facebook.com/docs/marketing-api/conversions-api/get-started](https://developers.facebook.com/docs/marketing-api/conversions-api/get-started).
3. Go to openmage system config `System > Configuration > Sales > Meta API > Conversions API`. Insert the parameters from step 1, save.


## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.