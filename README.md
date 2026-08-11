# Update Pilot Client Library

Enable updates for your WordPress plugins and themes from a custom update server like [WP Elevator Update Server](https://wpelevator.com/plugins/update-pilot-server).

## Usage

To check for updates for plugins and themes hosted outside of the WordPress.org repository, the plugin doing the update check must be active. Importantly, in WordPress multisites the plugin must be active on the main network site as the updates happen only there.

This library enables the following update workflows:

1. Require a dedicated updater plugin to be installed and active (such as [Update Pilot](https://wpelevator.com/plugins/update-pilot)). Best approach since it enables network-only instalation which is decoupled from the plugin activation state.

2. Bundle the updater functionality with your plugin. Requires the plugin to be active (also on the main network site on multisite) to check for updates. Possible to move the updater logic into a separate plugin file within your plugin (yes, WordPress does support this) to enable the updater logic even when the core plugin is selectively disabled.

### Option 1: Require the Updater Plugin

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$update_notice = new WPElevator\Update_Client\Plugin_Require(
	[
		'download_url' => 'https://updates.wpelevator.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
		'basename' => 'update-pilot/update-pilot.php',
		'name' => 'Update Pilot',
		'notice' => 'The Update Pilot plugin is required to enable updates to Your Plugin.', // "Install" or "Activate" button is appended to this notice.
		'network' => true,
		'signing_key' => 'y6BGnVLNL2AZLLKJzRHrDBTvMri+cLtvmMHBnf1M2S8=', // Optional. Base64 encoded Ed25519 public key used to sign the plugin package.
	]
);

add_action( 'plugins_loaded', [ $update_notice, 'init' ] );
```

### Option 2: Bundle the Updater

Register your plugin with the update server by adding the following to the main plugin file:

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$plugin_update = new WPElevator\Update_Client\Plugin_Update(
	plugin_basename( __FILE__ ),
	'https://updates.example.com/wp-json/update-pilot/v1/plugins',
	[
		'signing_key' => 'y6BGnVLNL2AZLLKJzRHrDBTvMri+cLtvmMHBnf1M2S8=', // Optional. Base64 encoded Ed25519 public key used to sign the update packages.
	]
);

add_action( 'plugins_loaded', [ $plugin_update, 'init' ] );
```

The update URL must implement the [WordPress plugin update API endpoint](https://wpelevator.com/guides/replace-wordpress-update-apis). The client posts the current plugin headers as a `plugins` JSON payload and returns the matching update object for the configured plugin basename. It also handles the WordPress plugin information modal through the same endpoint.

## Package Signature Verification

Both `Plugin_Require` and `Plugin_Update` accept an optional `signing_key` configuration value -- the base64 encoded Ed25519 public key matching the private key used by the update server (such as the [Update Pilot Server](https://wpelevator.com/plugins/update-pilot-server)) to sign the package ZIP files.

When the `signing_key` is set, the client enforces the WordPress core package signature verification for the associated package downloads. `Plugin_Require` verifies both the initial install (matched by the configured `download_url`) and the subsequent updates of the required plugin (matched by the configured `basename` since update package URLs are dynamic). `Plugin_Update` verifies the update packages of the registered plugin (matched by its basename):

- The download host is added to the list of hosts requiring signature verification (the `wp_signature_hosts` filter) which is limited to WordPress.org by default.

- The configured public key is added to the list of trusted signing keys (the `wp_trusted_keys` filter) used by `verify_file_signature()` in WP core. The key is exposed only while an associated package download is pending verification during the current request to avoid it being trusted during the signature verification of unrelated packages.

- The signature soft-fail is disabled for the package downloads (the `wp_signature_softfail` filter) so packages with a missing or invalid signature are never installed.

The update server must provide the package signature as a base64 encoded Ed25519 signature of the raw SHA-384 digest of the package ZIP file, as expected by `verify_file_signature()` in WP core. The signature is read from the `X-Content-Signature` HTTP response header of the package download, which is how the Update Pilot Server serves it. Alternatively, WP core can fetch newline-separated signatures from a `{package-url}.sig` file, but only if the package URL path ends with `.zip` or `.tar.gz` (or if a custom signature URL is provided via the `wp_signature_url` filter), so this does not apply to the typical REST API download endpoints.

Note that signature verification requires the PHP Sodium extension (or the `sodium_compat` polyfill bundled with WordPress core). `Plugin_Require` displays a warning notice on the Plugins screen if signature verification is not supported by the environment.

## TODO

- Document the namespace isolation.
