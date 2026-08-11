<?php

namespace WPElevator\Update_Client;

use RuntimeException;

class Plugin_Update {

	private string $api_url;

	private string $plugin_basename;

	private array $config;

	private ?Package_Signature $package_signature = null;

	public function __construct( string $plugin_basename, string $api_url, array $config = [] ) {
		$this->plugin_basename = $plugin_basename;
		$this->api_url = $api_url;
		$this->config = $config;
	}

	public function get_slug(): string {
		return dirname( $this->plugin_basename );
	}

	public function get_api_url(): string {
		if ( ! wp_http_supports( [ 'ssl' ] ) ) {
			return set_url_scheme( $this->api_url, 'http' );
		}

		return $this->api_url;
	}

	public static function from_update_uri_header( string $plugin_basename, array $config = [] ): self {
		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin_basename ]['UpdateURI'] ) ) {
			throw new RuntimeException( 'Failed to find the Update URI header in the plugin file' );
		}

		return new self( $plugin_basename, $plugins[ $plugin_basename ]['UpdateURI'], $config );
	}

	private function get_signing_key(): ?string {
		if ( isset( $this->config['signing_key'] ) && is_string( $this->config['signing_key'] ) && '' !== trim( $this->config['signing_key'] ) ) {
			return trim( $this->config['signing_key'] );
		}

		return null;
	}

	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );

		add_filter(
			sprintf( 'update_plugins_%s', wp_parse_url( $this->api_url, PHP_URL_HOST ) ),
			[ $this, 'update_by_hostname' ],
			10,
			4
		);

		$signing_key = $this->get_signing_key();

		if ( $signing_key ) {
			$this->package_signature = new Package_Signature( $signing_key );
			$this->package_signature->init();

			// Request the package signature verification when downloading the update package.
			add_filter( 'upgrader_pre_download', [ $this, 'filter_upgrader_pre_download' ], 10, 4 );
		}
	}

	/**
	 * Require a valid package signature when downloading the update package for our plugin.
	 *
	 * @param bool|\WP_Error $pre        Whether to short-circuit the download.
	 * @param string         $package    The package URL being downloaded.
	 * @param \WP_Upgrader   $upgrader   The upgrader instance.
	 * @param array          $hook_extra Extra hook arguments including the plugin basename.
	 * @return bool|\WP_Error
	 */
	public function filter_upgrader_pre_download( $pre, $package, $upgrader, $hook_extra ) {
		if ( false !== $pre ) {
			return $pre; // Another filter has short-circuited the download so the WP core signature verification will not run.
		}

		if ( isset( $this->package_signature ) && ! empty( $hook_extra['plugin'] ) && $this->plugin_basename === $hook_extra['plugin'] ) {
			$this->package_signature->enforce_for_url( $package );
		}

		return $pre;
	}

	/**
	 * * @param array|false $update {
	*     The plugin update data with the latest details. Default false.
	*
	*     @type string $id           Optional. ID of the plugin for update purposes, should be a URI
	*                                specified in the `Update URI` header field.
	*     @type string $slug         Slug of the plugin.
	*     @type string $version      The version of the plugin.
	*     @type string $url          The URL for details of the plugin.
	*     @type string $package      Optional. The update ZIP for the plugin.
	*     @type string $tested       Optional. The version of WordPress the plugin is tested against.
	*     @type string $requires_php Optional. The version of PHP which the plugin requires.
	*     @type bool   $autoupdate   Optional. Whether the plugin should automatically update.
	*     @type array  $icons        Optional. Array of plugin icons.
	*     @type array  $banners      Optional. Array of plugin banners.
	*     @type array  $banners_rtl  Optional. Array of plugin RTL banners.
	*     @type array  $translations {
	*         Optional. List of translation updates for the plugin.
	*
	*         @type string $language   The language the translation update is for.
	*         @type string $version    The version of the plugin this translation is for.
	*                                  This is not the version of the language file.
	*         @type string $updated    The update timestamp of the translation file.
	*                                  Should be a date in the `YYYY-MM-DD HH:MM:SS` format.
	*         @type string $package    The ZIP location containing the translation update.
	*         @type string $autoupdate Whether the translation should be automatically installed.
	*     }
	* }
	 */
	public function update_by_hostname( $update, $plugin_data, $plugin_file, $locales ) {
		return null;
	}

	/**
	 * Append our update after wp_update_plugins().
	 * Also called by wp_plugin_update_row().
	 *
	 * @return object
	 */
	public function check_update( object $updates ): object {
		if ( ! isset( $updates->last_checked ) ) {
			return $updates;
		}

		$plugins = get_plugins();

		if ( ! empty( $plugins[ $this->plugin_basename ] ) ) {
			$update = $this->get_update_for_version( $plugins[ $this->plugin_basename ]['Version'] );

			if ( ! empty( $update->new_version ) ) {
				$updates->response[ $this->plugin_basename ] = $update;
				$updates->checked[ $this->plugin_basename ] = $update->new_version;
				$updates->last_checked = time();
			}
		}

		return $updates;
	}

	private function get_update_for_version( string $version ): ?object {
		$payload = [
			'body' => [
				'package' => $this->plugin_basename,
				'version' => $version,
			],
		];

		$response = wp_remote_post( $this->get_api_url(), $payload );

		if ( ! is_wp_error( $response ) ) {
			return json_decode( wp_remote_retrieve_body( $response ) );
		}

		return null;
	}
}
