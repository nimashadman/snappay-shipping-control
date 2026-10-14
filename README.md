# SnappPay Shipping Control for WooCommerce

Disable selected WooCommerce shipping methods when a customer chooses a specific payment gateway.

## Example

If the customer chooses `snappay`, you can hide instant delivery, express courier, or any other shipping method that should not be available for that payment method.

## Features

- Works with WooCommerce checkout.
- Admin settings page under **WooCommerce > Shipping by Payment**.
- Supports gateway-specific shipping restrictions.
- Supports WooCommerce shipping instance IDs such as `flat_rate:3`.
- Refreshes checkout automatically after the customer changes the payment method.
- Adds a final validation step before order submission.

## Installation

1. Upload the `snappay-shipping-control` folder to `wp-content/plugins/`.
2. Activate **SnappPay Shipping Control for WooCommerce** from WordPress plugins.
3. Go to **WooCommerce > Shipping by Payment**.
4. For each payment gateway, select the shipping methods that must be disabled.
5. Save changes.

## How to configure SnappPay

1. Find the SnappPay payment gateway row in the settings page. Its gateway ID is usually something like `snappay`, but it depends on the payment plugin you use.
2. Tick the shipping methods you want to disable for SnappPay.
3. For your example, tick the instant delivery method in the SnappPay row.
4. Save.

After this, when a customer selects SnappPay during checkout, instant delivery will be removed from the available shipping methods.

## Technical notes

The plugin stores rules in the `ssc_gateway_shipping_rules` WordPress option.

Shipping rules are applied through the WooCommerce `woocommerce_package_rates` filter. The selected payment method is stored during WooCommerce checkout refresh and also validated during checkout submission.

## Developer

Developed by [Nima Shadman](https://nimashadman.ir).

## Suggested GitHub repository details

Project name:

```text
snappay-shipping-control
```

Short description:

```text
WooCommerce plugin to disable selected shipping methods based on the chosen payment gateway.
```
