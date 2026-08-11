<?php

use WPElevator\Update_Client\Plugin_Require;
use WPElevator\Update_Client\Plugin_Update;

class Update_Client_Test extends WP_UnitTestCase {

	const PUBLIC_KEY = 'APBHggGnhrL1VxZ71NI3ElbWTpdEOljwnPh+gkDKxRc=';

	const PACKAGE_CONTENTS = 'example-plugin-package-contents';

	public function test_plugin_available() {
		$this->assertTrue( class_exists( Plugin_Update::class ), 'Update client class exists' );
	}

	private function get_plugin_upgrader(): Plugin_Upgrader {
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		return new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	}

	private function fake_package_download( string $package_url, ?string $signature ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $package_url, $signature ) {
				if ( $package_url !== $url ) {
					return $pre;
				}

				file_put_contents( $args['filename'], self::PACKAGE_CONTENTS );

				return [
					'response' => [
						'code' => 200,
					],
					'headers' => $signature ? [ 'X-Content-Signature' => $signature ] : [],
				];
			},
			10,
			3
		);
	}

	private function sign_package( string $secret_key ): string {
		// Sign the raw SHA-384 digest of the package as expected by verify_file_signature().
		return base64_encode(
			sodium_crypto_sign_detached(
				hash( 'sha384', self::PACKAGE_CONTENTS, true ),
				$secret_key
			)
		);
	}

	private function skip_without_sodium(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The PHP Sodium extension is not available.' );
		}
	}

	public function test_plugin_update_verifies_signature_of_own_package() {
		$this->skip_without_sodium();

		$plugin_basename = 'example-plugin/example-plugin.php';
		$package_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

		$keypair = sodium_crypto_sign_keypair();

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins',
			[
				'signing_key' => base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
			]
		);

		$update->init();

		$this->fake_package_download( $package_url, $this->sign_package( sodium_crypto_sign_secretkey( $keypair ) ) );

		$upgrader = $this->get_plugin_upgrader();

		$this->assertFalse(
			apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => 'another-plugin/another-plugin.php' ] ),
			'Update packages of other plugins are left to WP core'
		);

		$this->assertSame(
			'/tmp/short-circuited.zip',
			apply_filters( 'upgrader_pre_download', '/tmp/short-circuited.zip', $package_url, $upgrader, [ 'plugin' => $plugin_basename ] ),
			'Downloads short-circuited by another filter are left untouched'
		);

		$package_file = apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => $plugin_basename ] );

		$this->assertIsString( $package_file, 'The signed update package is downloaded for the registered plugin' );

		$this->assertSame(
			self::PACKAGE_CONTENTS,
			file_get_contents( $package_file ),
			'The verified package file is handed over to WP core for installation'
		);

		$this->assertNotContains(
			base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
			apply_filters( 'wp_trusted_keys', [] ),
			'The configured public key is not left trusted after the package has been verified'
		);

		$this->unlink( $package_file );
	}

	public function test_plugin_update_aborts_on_unsigned_package() {
		$this->skip_without_sodium();

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

		$this->fake_package_download( $package_url, null );

		$package_file = apply_filters(
			'upgrader_pre_download',
			false,
			$package_url,
			$this->get_plugin_upgrader(),
			[ 'plugin' => $plugin_basename ]
		);

		$this->assertWPError( $package_file, 'An unsigned update package aborts the update' );

		$this->assertFalse(
			(bool) $package_file->get_error_data( 'softfail-filename' ),
			'The rejected package is not offered to WP_Upgrader::run() through the signature soft-fail'
		);
	}

	public function test_config_is_read_when_the_update_runs_rather_than_when_hooked() {
		$plugin_basename = 'example-plugin/example-plugin.php';
		$package_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

		$update = new Plugin_Update( $plugin_basename, 'https://updates.example.com/wp-json/update-pilot/v1/plugins' );

		$update->init();

		// Registered only after init(), as a plugin loading later on plugins_loaded would.
		add_filter(
			sprintf( 'wpelevator_update_client__update_config__%s', $plugin_basename ),
			function ( array $config ): array {
				$config['license_key'] = 'late-key';

				return $config;
			}
		);

		apply_filters( 'upgrader_pre_download', false, $package_url, $this->get_plugin_upgrader(), [ 'plugin' => $plugin_basename ] );

		$download_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $package_url );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'late-key' ),
			$download_request['headers']['Authorization'] ?? null,
			'A license key configured after the hooks are registered is still used'
		);
	}

	public function test_plugin_update_without_signing_key_skips_verification() {
		$update = new Plugin_Update(
			'example-plugin/example-plugin.php',
			'https://updates.example.com/wp-json/update-pilot/v1/plugins'
		);

		$update->init();

		$this->assertFalse(
			apply_filters(
				'upgrader_pre_download',
				false,
				'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin',
				$this->get_plugin_upgrader(),
				[ 'plugin' => 'example-plugin/example-plugin.php' ]
			),
			'The update package is downloaded by WP core without a signing key'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'No trusted keys are registered without a signing key'
		);
	}

	private function fake_installed_plugin( string $plugin_basename, string $version = '1.0.0' ): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		wp_cache_set(
			'plugins',
			[
				'' => [
					$plugin_basename => [
						'Name' => 'Example Plugin',
						'Version' => $version,
					],
				],
			],
			'plugins'
		);
	}

	private function get_authorization_header_for_key( string $license_key ): string {
		return sprintf(
			'Basic %s',
			base64_encode( sprintf( '%s:%s', wp_parse_url( home_url(), PHP_URL_HOST ), $license_key ) )
		);
	}

	private function do_update_check( Plugin_Update $update, ?array &$request_args ): object {
		$request_args = null;

		$intercept = function ( $pre, $args, $url ) use ( &$request_args ) {
			$request_args = $args;

			return [
				'headers' => [],
				'body' => '{"new_version":"1.1.0"}',
				'response' => [
					'code' => 200,
					'message' => 'OK',
				],
				'cookies' => [],
			];
		};

		add_filter( 'pre_http_request', $intercept, 10, 3 );

		$updates = new stdClass();
		$updates->last_checked = time();
		$updates->response = [];
		$updates->checked = [];

		$updates = apply_filters( 'pre_set_site_transient_update_plugins', $updates );

		remove_filter( 'pre_http_request', $intercept, 10 );

		return $updates;
	}

	public function test_plugin_update_sends_license_key_with_update_check() {
		$plugin_basename = 'example-plugin/example-plugin.php';

		$this->fake_installed_plugin( $plugin_basename );

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins',
			[
				'license_key' => 'secret-key',
			]
		);

		$update->init();

		$updates = $this->do_update_check( $update, $request_args );

		$this->assertIsArray( $request_args, 'Update check request is sent to the update server' );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'secret-key' ),
			$request_args['headers']['Authorization'] ?? null,
			'Update check request includes the license key as basic auth with the site hostname as the username'
		);

		$this->assertArrayHasKey(
			$plugin_basename,
			$updates->response,
			'Update response is registered for the plugin with a valid license key'
		);
	}

	public function test_plugin_update_config_filter_overrides_license_key() {
		$plugin_basename = 'example-plugin/example-plugin.php';

		$this->fake_installed_plugin( $plugin_basename );

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins',
			[
				'license_key' => 'config-key',
			]
		);

		$override = function ( array $config ): array {
			$config['license_key'] = 'filtered-key';

			return $config;
		};

		add_filter( sprintf( 'wpelevator_update_client__update_config__%s', $plugin_basename ), $override );

		$update->init();

		$this->do_update_check( $update, $request_args );

		remove_filter( sprintf( 'wpelevator_update_client__update_config__%s', $plugin_basename ), $override );

		$this->assertIsArray( $request_args, 'Update check request is sent to the update server' );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'filtered-key' ),
			$request_args['headers']['Authorization'] ?? null,
			'The config filter overrides the configured license key'
		);
	}

	public function test_plugin_update_without_license_key_sends_no_auth_header() {
		$plugin_basename = 'example-plugin/example-plugin.php';

		$this->fake_installed_plugin( $plugin_basename );

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins'
		);

		$update->init();

		$this->do_update_check( $update, $request_args );

		$this->assertIsArray( $request_args, 'Update check request is sent to the update server' );

		$this->assertArrayNotHasKey(
			'Authorization',
			$request_args['headers'] ?? [],
			'Update check request does not include an authorization header without a license key'
		);

		$download_request = apply_filters(
			'http_request_args',
			[ 'headers' => [] ],
			'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin'
		);

		$this->assertArrayNotHasKey(
			'Authorization',
			$download_request['headers'],
			'No authorization header is added to the package download without a license key'
		);
	}

	public function test_plugin_update_adds_auth_header_to_package_download() {
		$plugin_basename = 'example-plugin/example-plugin.php';
		$package_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

		$update = new Plugin_Update(
			$plugin_basename,
			'https://updates.example.com/wp-json/update-pilot/v1/plugins',
			[
				'license_key' => 'secret-key',
			]
		);

		$update->init();

		$upgrader = $this->get_plugin_upgrader();

		apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => 'another-plugin/another-plugin.php' ] );

		$other_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $package_url );

		$this->assertArrayNotHasKey(
			'Authorization',
			$other_request['headers'],
			'Authorization header is not added to package downloads of other plugins'
		);

		apply_filters( 'upgrader_pre_download', false, $package_url, $upgrader, [ 'plugin' => $plugin_basename ] );

		$download_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $package_url );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'secret-key' ),
			$download_request['headers']['Authorization'] ?? null,
			'Authorization header is added to the update package download of the registered plugin'
		);

		$existing_auth_request = apply_filters(
			'http_request_args',
			[ 'headers' => [ 'Authorization' => 'Basic existing' ] ],
			$package_url
		);

		$this->assertSame(
			'Basic existing',
			$existing_auth_request['headers']['Authorization'],
			'An existing authorization header is never overridden'
		);

		$untracked_request = apply_filters( 'http_request_args', [ 'headers' => [] ], 'https://updates.example.com/other-download.zip' );

		$this->assertArrayNotHasKey(
			'Authorization',
			$untracked_request['headers'],
			'Authorization header is not added to downloads that were not registered as update packages'
		);
	}

	public function test_plugin_require_verifies_signature_of_download() {
		$this->skip_without_sodium();

		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$keypair = sodium_crypto_sign_keypair();

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
				'signing_key' => base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
			]
		);

		$require->init();

		$this->fake_package_download( $download_url, $this->sign_package( sodium_crypto_sign_secretkey( $keypair ) ) );

		$upgrader = $this->get_plugin_upgrader();
		$install_hook_extra = [
			'type' => 'plugin',
			'action' => 'install',
		];

		$this->assertFalse(
			apply_filters( 'upgrader_pre_download', false, 'https://updates.example.com/other-download.zip', $upgrader, $install_hook_extra ),
			'Only the known plugin download URL is verified'
		);

		$package_file = apply_filters( 'upgrader_pre_download', false, $download_url, $upgrader, $install_hook_extra );

		$this->assertIsString( $package_file, 'The signed plugin package is downloaded for the known download URL' );

		$this->assertSame(
			self::PACKAGE_CONTENTS,
			file_get_contents( $package_file ),
			'The verified package file is handed over to WP core for installation'
		);

		$this->unlink( $package_file );
	}

	public function test_plugin_require_aborts_on_unsigned_download() {
		$this->skip_without_sodium();

		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
				'signing_key' => self::PUBLIC_KEY,
			]
		);

		$require->init();

		$this->fake_package_download( $download_url, null );

		$this->assertWPError(
			apply_filters(
				'upgrader_pre_download',
				false,
				$download_url,
				$this->get_plugin_upgrader(),
				[
					'type' => 'plugin',
					'action' => 'install',
				]
			),
			'An unsigned plugin package aborts the install'
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
			apply_filters(
				'upgrader_pre_download',
				false,
				'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
				$this->get_plugin_upgrader(),
				[
					'type' => 'plugin',
					'action' => 'install',
				]
			),
			'The plugin package is downloaded by WP core without a signing key'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'No trusted keys are registered without a signing key'
		);
	}

	public function test_plugin_require_adds_auth_header_to_download() {
		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
				'license_key' => 'secret-key',
			]
		);

		$require->init();

		$download_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $download_url );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'secret-key' ),
			$download_request['headers']['Authorization'] ?? null,
			'Authorization header is added to the download request of the required plugin'
		);

		$other_request = apply_filters( 'http_request_args', [ 'headers' => [] ], 'https://updates.example.com/other-download.zip' );

		$this->assertArrayNotHasKey(
			'Authorization',
			$other_request['headers'],
			'Authorization header is not added to requests other than the required plugin download'
		);

		$existing_auth_request = apply_filters(
			'http_request_args',
			[ 'headers' => [ 'Authorization' => 'Basic existing' ] ],
			$download_url
		);

		$this->assertSame(
			'Basic existing',
			$existing_auth_request['headers']['Authorization'],
			'An existing authorization header is never overridden'
		);
	}

	public function test_plugin_require_config_filter_overrides_license_key() {
		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
				'license_key' => 'config-key',
			]
		);

		$override = function ( array $config ): array {
			$config['license_key'] = 'filtered-key';

			return $config;
		};

		// The filter name includes the basename of the required plugin (the default config).
		add_filter( 'wpelevator_update_client__require_config__update-pilot/update-pilot.php', $override );

		$require->init();

		$download_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $download_url );

		remove_filter( 'wpelevator_update_client__require_config__update-pilot/update-pilot.php', $override );

		$this->assertSame(
			$this->get_authorization_header_for_key( 'filtered-key' ),
			$download_request['headers']['Authorization'] ?? null,
			'The config filter overrides the configured license key'
		);
	}

	public function test_plugin_require_without_license_key_sends_no_auth_header() {
		$download_url = 'https://updates.example.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot';

		$require = new Plugin_Require(
			[
				'download_url' => $download_url,
			]
		);

		$require->init();

		$download_request = apply_filters( 'http_request_args', [ 'headers' => [] ], $download_url );

		$this->assertArrayNotHasKey(
			'Authorization',
			$download_request['headers'],
			'No authorization header is added to the download request without a license key'
		);
	}
}
