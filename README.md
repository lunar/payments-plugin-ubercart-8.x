# Lunar Online Payments plugin for Ubercart 8.x-4.x

## Supported Ubercart versions

*The plugin has been tested with most versions of Drupal/Ubercart at every iteration. We recommend using the latest version of Ubercart, but if that is not possible for some reason, test the plugin with your Ubercart version and it would probably function properly.*

## Installation

Once you have installed Ubercart on your Drupal setup, follow these simple steps:
1. Signup at [lunar.app](https://lunar.app) (it's free)
1. Create an account
1. Create an app key for your Drupal website
1. Upload the ```uc_lunar.zip``` trough the Drupal Admin (You can also find the latest release at https://www.drupal.org/project/uc_lunar)
1. Download and install the Paylike PHP Library version 1.0.8 or newer from https://github.com/paylike/php-api/releases. Use `composer require lunar/payments-api-sdk` in the vendors folder.
If you use `composer require drupal/uc_lunar` you can skip this step.
1. Activate the plugin through the 'Extend' screen in Drupal.
1. Visit your Ubercart Store Administration page, Configuration section, and enable the gateway under the Payment methods. (admin/store/config/payment)
1. Select the default credit transaction type. This module supports immediate or delayed capture modes. 
   - Immediate capture will be done when users confirm their orders. 
   - In delayed mode administrator should capture the money manually from orders administration page (admin/store/orders/view). Select an order and click "Process card" button in Payment block on the top. Check "PRIOR AUTHORIZATIONS" block to manually capture a needed amount of money.
1. Insert Lunar API keys, from https://lunar.app (admin/store/config/payment/method/credit_card)

## Updating settings

Under the Lunar payment method settings, you can:
 * Update the payment method text in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the title that shows up in the payment popup
 * Add public & app keys
 * Change the capture type (Authorize+Capture / Authorize only)

 ## How to capture/refund/void
- You can do capture/refund/void to an order using the Payment box in the order View Tab by press `Process Lunar transactions` link.
- The amount for partial capture, refund or void can be specified in `Charge Amount` input field.

 1. Capture
    * In Instant mode, the orders are captured automatically.
    * In delayed mode you can capture an order selecting authorized transaction and then click `Capture amount to this authorization` button.
 2. Refund
    * To refund an order selecting authorized transaction and then click `Refund` button.
 3. Void
    * To void an order selecting authorized transaction and then click `Void authorization` button.

## Available features
1. Capture
   * Ubercart admin panel: full/partial capture
   * Lunar admin panel: full/partial capture
2. Refund
   * Ubercart admin panel: full/partial refund
   * Lunar admin panel: full/partial refund
3. Void
   * Ubercart admin panel: full/partial void
   * Lunar admin panel: full/partial void