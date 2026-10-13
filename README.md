# WooCommerce to Gotify Notifications

[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)](https://woocommerce.com)
[![HPOS Compatible](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-brightgreen.svg)](https://woocommerce.com)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**WooCommerce to Gotify Notifications** is a lightweight, high-performance WordPress plugin that sends instant self-hosted push notifications to your Gotify server whenever a new WooCommerce order is placed.

Keep track of sales in real time without relying on third-party SaaS notification services or bloated email channels. Designed for speed, reliability, and security.

## Key Features

* 🚀 **Instant Push Notifications:** Receive instant mobile and desktop alerts via Gotify the moment a customer completes checkout.
* ⚡ **Asynchronous & Non-Blocking:** Powered by WooCommerce’s native Action Scheduler to process notifications in the background—ensuring zero impact on checkout speed or site performance.
* 🛒 **Block & Classic Checkout Support:** Native compatibility with both modern WooCommerce Cart & Checkout Blocks and classic shortcodes.
* 📦 **WooCommerce HPOS Compatible:** Fully compliant with High-Performance Order Storage (HPOS) and traditional post meta storage.
* 🔤 **Dynamic Placeholders:** Build tailored alerts with detailed order dynamic tags:
  `{order_id}`, `{customer_name}`, `{customer_email}`, `{customer_phone}`, `{total}`, `{currency}`, `{items_count}`, `{payment_method}`, `{order_status}`, `{billing_city}`, `{billing_country}`, `{admin_url}`, `{site_name}`, `{site_url}`, `{date}`
* 🔒 **Security & Basic Auth:** Native support for Gotify instances protected behind HTTP Basic Authentication.
* 🧪 **Built-in Connection Tester:** Send test alerts directly from the settings panel to verify your server configuration.
* 🧹 **Clean Uninstall:** Leaves no database clutter—automatically purges plugin options, scheduled tasks, and metadata upon removal.

## Requirements

- WordPress 5.6 or higher
- WooCommerce 5.0 or higher
- PHP 7.2 or higher
- A self-hosted Gotify server with an Application Token

## Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   wp-content/plugins/wc-gotify-notify
   ```
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WooCommerce > Gotify Notifications** to configure your settings:
   - **Gotify Server URL** (e.g., `https://gotify.example.com`)
   - **Application Token**
   - **Priority** (1-10)
   - **Notification Title & Message Templates**
4. Click **Send Test Notification** to confirm your configuration works.

## License

This project is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
