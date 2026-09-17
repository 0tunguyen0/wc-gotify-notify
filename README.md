# WooCommerce to Gotify Notifications

Sends push notifications to your self-hosted [Gotify](https://gotify.net/) server whenever a new WooCommerce order is placed.

## Features

- 🚀 **Instant Push Notifications**: Get notified on mobile and desktop via Gotify as soon as an order is placed.
- ⚡ **Asynchronous & Non-Blocking**: Utilizes WooCommerce's built-in Action Scheduler for background processing so checkout performance is never affected.
- 🛒 **Block & Classic Checkout Support**: Works with classic shortcode checkouts and modern block-based (Cart & Checkout blocks) checkouts.
- 📦 **WooCommerce HPOS Compatible**: Full support for High-Performance Order Storage (HPOS) and traditional post meta storage.
- 🔤 **Dynamic Placeholders**: Customize your notification title and body using rich order placeholders:
  - `{order_id}`
  - `{customer_name}`
  - `{customer_email}`
  - `{customer_phone}`
  - `{total}`
  - `{currency}`
  - `{items_count}`
  - `{payment_method}`
  - `{order_status}`
  - `{billing_city}`
  - `{billing_country}`
  - `{admin_url}`
  - `{site_name}`
  - `{site_url}`
  - `{date}`
- 🔒 **Security & Basic Auth**: Supports servers behind HTTP Basic Authentication.
- 🧪 **Built-in Test Tool**: Send test notifications directly from the settings page to verify your Gotify server connection.
- 🧹 **Clean Uninstall**: Properly cleans up plugin options, scheduled tasks, and metadata upon deletion.

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
