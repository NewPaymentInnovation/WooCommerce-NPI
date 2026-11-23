Disclaimer: Please note that we no longer support older versions of SDKs and
Modules. We recommend that the latest versions are used. Please note version
4.1.3 contains a rebuild of the core module infrastructure, please ensure you
take relevant measures when updating to ensure compatibility with your 
integrations.

# NPI Gateway

NPI Gateway is a WooCommerce payment plugin for processing card 
payments, Apple Pay, Google Pay, and hosted payment forms.

Current plugin version: **4.1.3**

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Optional

- WooCommerce Subscriptions 8.6.0+ (for recurring payments)

## Supported Payment Methods

| Method | Features |
|---|---|
| **Card** | Hosted Fields, tokenisation (saved cards), 3D Secure, refunds, subscriptions |
| **Apple Pay** | Express checkout, refunds, subscriptions |
| **Google Pay** | Express checkout, 3D Secure, refunds, subscriptions |
| **Hosted** | Hosted Payment Form (v1, modal, custom URL), refunds, subscriptions |

All methods support both WooCommerce classic checkout and block-based checkout.

## Installation

### Install from ZIP (WP Admin)

1. Download the plugin ZIP package.
2. Log in to your WordPress admin dashboard.
3. Go to **Plugins -> Add New -> Upload Plugin**.
4. Upload the ZIP file, then click **Install Now**.
5. Activate the plugin.

### Manual Installation

1. Upload the `woocommerce-payment-module` folder to `wp-content/plugins/`.
2. Activate the plugin from **Plugins -> Installed Plugins**.

## Configuration

After activation, configure the plugin in two places:

### 1) Module Settings

Go to **WooCommerce -> NPI Gateway**.

| Setting | Description |
|---|---|
| Enable Plugin | Enables or disables the plugin globally |
| Merchant ID | Your gateway merchant ID |
| Merchant Secret | Your gateway merchant signature key |
| Country Code | ISO 3166-1 numeric country code of the merchant (for example, `826` for the United Kingdom) |
| Gateway Hostname | Gateway URL (for example, `gateway.exampled.com`) |
| Debug Enabled | Enables logging to WooCommerce logs |
| Debug Verbose | Selects which log levels are recorded |

Logs are available under **WooCommerce -> Status -> Logs**.

### 2) Payment Method Settings

Go to **WooCommerce -> Settings -> Payments** and configure each method:

## Updating

1. Back up your store files and database.
2. Deactivate and remove the old plugin version.
3. Install the new version using one of the installation methods above.
4. Re-check module and payment method settings after activation.

## Disclaimer

Sample code, SDKs and modules have been created as reference material only.
Modules are developed and tested against vanilla base platform installs only.
Any further module compatibility would need to be tested by the 
user/merchant/developer. Version support is as shown within the associated 
VERSION section. All sample code, SDKs and modules offer foundation transaction 
functionality for merchant and developers to use as a guide and/or to adapt, 
enhance or otherwise build upon. For the avoidance of doubt, this means that 
some desired functionality may not be useable or exist. All sample code, SDKs 
or modules that are used will require complete full end to end testing by the 
user/merchant/developer. Further to this, use of any sample code, SDKs or 
modules, Open Payment Network bears no responsibility for; nor extends any 
warranty in regard to; nor accepts any liability arising due to any changes 
or errors in functionality that may result. Developers, merchants or other 
users of any sample code, SDKs or modules accept these conditions de facto 
upon usage.
