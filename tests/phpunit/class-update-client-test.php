<?php

use WPElevator\Update_Client\Plugin_Require;
use WPElevator\Update_Client\Plugin_Update;

class Update_Client_Test extends WP_UnitTestCase {

	const PUBLIC_KEY = 'APBHggGnhrL1VxZ71NI3ElbWTpdEOljwnPh+gkDKxRc=';

	public function test_plugin_available() {
		$this->assertTrue( class_exists( Plugin_Update::class ), 'Update client class exists' );
	}

	private function get_plugin_upgrader(): Plugin_Upgrader {
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		return new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	}

	public function test_plugin_update_enforces_signature_verification_for_own_package() {
		$plugin_basename = 'example-plugin/example-plugin.php';
		$package_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins',
			[
				'signing_key' => self::PUBLIC_KEY,
			]
		);

		$update->init();

		$upgrader = $this->get_plugin_upgrader();

		apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => 'another-plugin/another-plugin.php' ] );

		$this->assertNotContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'Signature verification is not enforced for update packages of other plugins'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'The configured public key is not trusted before an update package download is pending verification'
		);

		apply_filters( 'upgrader_pre_download', '/tmp/short-circuited.zip', $package_url, $upgrader, [ 'plugin' => $plugin_basename ] );

		$this->assertNotContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'Signature verification is not enforced when another filter short-circuits the download'
		);

		apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => $plugin_basename ] );

		$this->assertContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'Signature verification is enforced for the update package host of the registered plugin'
		);

		$this->assertFalse(
			apply_filters( 'wp_signature_softfail', true, $package_url ),
			'Signature errors abort the update for the registered package host'
		);

		$this->assertContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'The configured public key is trusted during signature verification'
		);
	}

	public function test_plugin_update_without_signing_key_skips_verification() {
		$update = new Plugin_Update(
			'example-plugin/example-plugin.php',
			'https://updates.example.com/wp-json/update-pilot/v1/plugins'
		);

		$update->init();

		$this->assertFalse(
			has_filter( 'upgrader_pre_download', [ $update, 'filter_upgrader_pre_download' ] ),
			'Package download filter is not registered without a signing key'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'No trusted keys are registered without a signing key'
		);
	}

	public function test_plugin_require_enforces_signature_verification_for_download() {
		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
				'signing_key' => self::PUBLIC_KEY,
			]
		);

		$require->init();

		$upgrader = $this->get_plugin_upgrader();
		$install_hook_extra = [
			'type' => 'plugin',
			'action' => 'install',
		];

		apply_filters( 'upgrader_pre_download', false, 'https://updates.example.com/other-download.zip', $upgrader, $install_hook_extra );

		$this->assertNotContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'Signature verification is enforced only for the known plugin download URL'
		);

		apply_filters( 'upgrader_pre_download', false, $download_url, $upgrader, $install_hook_extra );

		$this->assertContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'Signature verification is enforced for the host of the known plugin download URL'
		);

		$this->assertFalse(
			apply_filters( 'wp_signature_softfail', true, $download_url ),
			'Signature errors abort the install of the required plugin'
		);

		$this->assertContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'The configured public key is trusted during signature verification'
		);
	}

	public function test_plugin_require_without_signing_key_skips_verification() {
		$require = new Plugin_Require(
			[
				'download_url' => 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
			]
		);

		$require->init();

		$this->assertFalse(
			has_filter( 'upgrader_pre_download', [ $require, 'filter_upgrader_pre_download' ] ),
			'Package download filter is not registered without a signing key'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'No trusted keys are registered without a signing key'
		);
	}
}
