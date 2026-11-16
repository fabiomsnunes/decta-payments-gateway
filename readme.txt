=== Decta Payment Gateway ===

Tags: credit card, payment request, woocommerce
Requires PHP: 7.0
Stable tag: 3.8
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

= Description =
DECTA can be easily integrated into the billing process of e-commerce websites and mobile apps.
If your online store is using WooCommerce, download and set up this ready-made plugin.

= Manual installation =
If you have faced errors with "Error establishing a database connection" - disconnect from your VPN and make init again
Go to the WooCommerce administration panel and click on the “Plugins” - “Add new”.
Click on the “Upload Plugin” button. Then choose and upload from your PC WooCommerce downloaded module archive and click on “Install now”.
After downloading module archive, click on “Activate plugin”. Now the plugin is activated.
To set up module, click on “WooCommerce” - “Settings”. Choose the “Payments” tab.
On “Payments” tab find just added payment gateway and click on the “Manage” button.

The module provides several parameters that should be filled out for it to work correctly:
- Public Key – available in the API section the administration panel;
- Secret Key - available in the API section the administration panel.
You also need to choose “Enable API” and “Debug log” parameters.
- “Debug log” provides the ability to log Gateway events.

= Automatic installation =
Automatic installation  will be available soon

= Updating =
Automatic updates will be available soon

= Does this require an SSL certificate? =
Yes! In Live Mode, an SSL certificate must be installed on your site to use Payment Gateway.

= webhook url =
https://www.yourdomain.com/?rest_route=/wc-pmg/v1/webhook
