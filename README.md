# Update Pilot Client Library

Enable updates for your WordPress plugins and themes from a custom update server like [WP Elevator Update Server](https://wpelevator.com/plugins/update-pilot-server).

## Usage

TODO: Document the namespace isolation using `vendor-isolator`.

Register your plugin with the update server by adding the following to the main plugin file:

	<?php

	$plugin_update = new WPElevator\Update_Client\Plugin_Update(
		plugin_basename( __FILE__ ),
		'https://updates.example.com/wp-json/update-pilot/v1/update-check',
	);

	add_action( 'plugins_loaded', [ $plugin_update, 'init' ] );

