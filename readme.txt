=== Unica Woo Affiliate ===
Contributors: tungpham42, hoanganhphan91
Tags: WooCommerce, affiliate, external products, product sync, API integration
Requires at least: 5.2
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically import external/affiliate products from Unica.vn into your WooCommerce store. Keep your affiliate catalog updated with ease.

== Description ==

**Unica Woo Affiliate** is a WordPress plugin designed to import and synchronize external/affiliate products from Unica.vn into WooCommerce. By connecting with the Unica.vn API, this plugin fetches product information like titles, descriptions, prices, images, and affiliate links, enabling WooCommerce store owners to promote Unica.vn products seamlessly.

### Important Note to Users
This plugin requires you to log in with your **Unica.vn email and password** to fetch data about courses and generate affiliate links for your WooCommerce store. These credentials are securely sent to Unica.vn through their API and are used solely for the purpose of importing and synchronizing product data. The plugin does not store your password locally; only an API token is stored to maintain the connection.

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
   - Enter your Unica.vn **email**, **password**, preferred **button text** for the affiliate link, and **coupon code** if applicable.
2. **Manual Product Import**:
   - Under **Manual Product Import**, click "Import products now" to fetch products immediately from Unica.vn.
3. **Product Management**: Imported products will appear in **WooCommerce > Products** as external/affiliate products, linking directly to Unica.vn with your specified button text and coupon code.

== External Services ==

This plugin connects to the Unica.vn API to import product data into WooCommerce. It is required to fetch and synchronize affiliate product information like titles, descriptions, prices, images, and affiliate URLs.

**What data is sent:**
- The Unica.vn email and password you provide during plugin configuration are securely transmitted to Unica.vn to authenticate your account and fetch product data. After successful authentication, an API token is stored locally for subsequent communication.

**When data is sent:**
- Data is sent only during the initial configuration and when the plugin syncs products (either manually or automatically).

**Service provider:**
- Unica.vn API: [Terms of Service](https://unica.vn/dieu-khoan-dich-vu.html) | [Privacy Policy](https://unica.vn/chinh-sach-bao-mat.html)

== Frequently Asked Questions ==

= Why does the plugin need my Unica.vn email and password? =
Your Unica.vn credentials are required to authenticate with the Unica.vn API and fetch course data to display in your WooCommerce store. The plugin does not store your password permanently but uses it to retrieve an API token for future communication.

= How do I get my Unica.vn Affiliate ID? =
Log in to your Unica.vn account, request to ** https://unica.vn/api/getToken/**, and generate an Affiliate ID and Token. After that, you can login with our plugin with your Unica account.

= Can I choose which products to import? =
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
