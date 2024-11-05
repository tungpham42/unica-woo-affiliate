=== Unica Woo Affiliate - Auto Generate External/Affiliate Products from Unica.vn ===
Contributors: Tung Pham, Hoang Anh Phan
Tags: WooCommerce, Unica.vn, external products, affiliate products, API integration, product sync
Requires at least: 5.2
Tested up to: 6.6.2
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically import external/affiliate products from Unica.vn into your WooCommerce store. Keep your affiliate catalog updated with ease.

== Description ==

**Unica Woo Affiliate** is a WordPress plugin designed to import and synchronize external/affiliate products from Unica.vn into WooCommerce. By connecting with the Unica.vn API, this plugin fetches product information like titles, descriptions, prices, images, and affiliate links, enabling WooCommerce store owners to promote Unica.vn products seamlessly.

### Features
* Import Unica.vn products as external/affiliate products in WooCommerce.
* Sync product data such as title, description, price, affiliate URL, and coupon codes.
* Schedule automated updates to keep your WooCommerce catalog up-to-date.
* Simple configuration to set up and manage your Unica.vn connection.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/unica-woo-affiliate` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Unica Affiliate** in the WordPress sidebar to configure the plugin.

== Usage ==

1. **Configure Plugin Settings**:
   - Go to the **Unica Affiliate** menu in the WordPress sidebar.
   - Enter your Unica.vn **username**, **password**, preferred **button text** for the affiliate link, and **coupon code** if applicable.
2. **Manual Product Import**:
   - Under **Manual Product Import**, click "Import products now" to fetch products immediately from Unica.vn.
3. **Product Management**: Imported products will appear in **WooCommerce > Products** as external/affiliate products, linking directly to Unica.vn with your specified button text and coupon code.

== Frequently Asked Questions ==

= How do I get my Unica.vn Affiliate ID? =
Log in to your Unica.vn account, navigate to **https://unica.vn/dashboard/affiliate/api**, and generate an Affiliate ID. After that, you can login with our plugin with your Unica account.

= What if I only want specific products from Unica.vn? =
Currently, the plugin imports all available products from Unica.vn.

= How does product updating work? =
The plugin checks for changes based on your chosen synchronization schedule. You can also run a manual sync to refresh product information immediately.

== Screenshots ==

1. **Settings Page** - Configure your Unica.vn API credentials, button text, and coupon code.
2. **Manual Product Import** - Sync products manually from Unica.vn.
3. **External Product List** - View and manage imported affiliate products in WooCommerce.

== Changelog ==

= 1.0.0 =
* Initial release.
* Import external/affiliate products from Unica.vn API.

== Upgrade Notice ==

= 1.0.0 =
Initial release - import and sync Unica.vn products as WooCommerce external/affiliate products.

== License ==

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
