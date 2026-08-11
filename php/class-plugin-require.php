<?php

namespace WPElevator\Update_Client;

use WP_Error;
use Plugin_Upgrader;
use Automatic_Upgrader_Skin;

class Plugin_Require {

	private const INSTALL_ACTION = 'wpelevator-update-client-install-plugin';

	private array $config;

	private array $errors = [];

	private ?Package_Signature $package_signature;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function init(): void {
		add_action( 'admin_notices', [ $this, 'action_render_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'action_render_notice' ] );
		add_action( 'load-plugins.php', [ $this, 'action_install_plugin' ] );

		$signing_key = $this->get_signing_key();

		if ( $signing_key ) {
			$this->package_signature = new Package_Signature( $signing_key );
			$this->package_signature->init();

			// Request the package signature verification when downloading the plugin.
			add_filter( 'upgrader_pre_download', [ $this, 'filter_upgrader_pre_download' ], 10, 2 );
		}
	}

	private function get_config(): array {
		$config_default = [
			// TODO: Add the signing_key to the default config.
			'download_url' => 'https://updates.wpelevator.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
			'basename' => 'update-pilot/update-pilot.php',
			'name' => 'Update Pilot',
			'notice' => __( 'The Update Pilot plugin is required' ),
			'network' => true,
		];

		return array_merge( $config_default, $this->config );
	}

	private function get_config_value( string $key ) {
		$config = $this->get_config();

		return $config[ $key ] ?? null;
	}

	private function is_network_plugin(): bool {
		return (bool) $this->get_config_value( 'network' );
	}

	private function get_basename(): string {
		return (string) $this->get_config_value( 'basename' );
	}

	private function get_update_plugin(): Plugin {
		return new Plugin( $this->get_basename() );
	}

	private function get_download_url(): string {
		return (string) $this->get_config_value( 'download_url' );
	}

	private function get_signing_key(): ?string {
		$signing_key = $this->get_config_value( 'signing_key' );

		if ( is_string( $signing_key ) && '' !== trim( $signing_key ) ) {
			return trim( $signing_key );
		}

		return null;
	}

	public function filter_upgrader_pre_download( $pre, $package ) {
		if ( false !== $pre ) {
			return $pre; // Another filter has short-circuited the download so the WP core signature verification will not run.
		}

		// Enforce the signature verification only during the initial install since updates are handled by the plugin.
		if ( isset( $this->package_signature ) && $this->get_download_url() === $package ) {
			$this->package_signature->enforce_for_url( $package );
		}

		return $pre;
	}

	private function get_nonce_action(): string {
		return sprintf( '%s-%s', self::INSTALL_ACTION, md5( $this->get_download_url() ) );
	}

	private function get_install_url(): ?string {
		$url = add_query_arg(
			[
				'action' => self::INSTALL_ACTION,
				'plugin' => md5( $this->get_download_url() ),
			],
			is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) // Run on the current site where the calling plugin is active.
		);

		return wp_nonce_url( $url, $this->get_nonce_action() );
	}

	public function action_install_plugin(): void {
		if ( ! isset( $_GET['action'], $_GET['plugin'] ) || self::INSTALL_ACTION !== $_GET['action'] ) {
			return;
		}

		$download_url = $this->get_download_url();
		if ( empty( $download_url ) || md5( $download_url ) !== $_GET['plugin'] ) {
			return;
		}

		check_admin_referer( $this->get_nonce_action() );

		$plugin = new Plugin( $this->get_basename() );

		// Attempt to install the plugin, if not already installed.
		if ( ! $plugin->is_installed() && current_user_can( 'install_plugins' ) ) {
			if ( ! class_exists( Plugin_Upgrader::class ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}

			$upgrader_skin = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $upgrader_skin );
			$install = $upgrader->install( $download_url );

			if ( is_wp_error( $install ) ) {
				$this->errors[] = $install;
			}

			$messages = $upgrader_skin->get_upgrade_messages();

			if ( ! empty( $messages ) ) {
				$this->errors[] = new WP_Error(
					'plugin_install',
					implode( ' ', $messages ),
					[ 'type' => true === $install ? 'success' : 'error' ]
				);
			}
		}

		// Attempt to activate, if present but not active.
		if ( $plugin->is_installed() && ! $plugin->is_active() && current_user_can( 'activate_plugins' ) ) {
			$activated = activate_plugin( $this->get_basename(), '', $this->is_network_plugin() );

			if ( is_wp_error( $activated ) ) {
				$this->errors[] = $activated;
			}
		}
	}

	public function action_render_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) ) {
			return; // Show notice on plugin screen only.
		}

		if ( null !== $this->package_signature && ! $this->package_signature->can_verify() ) {
			$this->errors[] = new WP_Error(
				'plugin_signature_unsupported',
				sprintf(
					/* translators: %s: Required plugin name. */
					__( 'The PHP Sodium extension is required to verify the authenticity of the %s plugin downloads.' ),
					$this->get_config_value( 'name' )
				),
				[ 'type' => 'warning' ]
			);
		}

		$update_plugin = $this->get_update_plugin();
		$notice = $this->get_config_value( 'notice' );

		if ( ! $notice ) {
			$notice = sprintf(
				/* translators: 1: Required plugin name. */
				__( 'The %1$s plugin is required.' ),
				$this->get_config_value( 'name' )
			);
		}

		if ( ! $update_plugin->is_installed() && current_user_can( 'install_plugins' ) ) {
			$notice_action = sprintf(
				' <a class="button" href="%s">%s</a>',
				esc_url( $this->get_install_url() ),
				esc_html__( 'Install' )
			);
		} elseif ( ! $update_plugin->is_active() && current_user_can( 'activate_plugins' ) ) {
			$notice_action = sprintf(
				' <a class="button" href="%s">%s</a>',
				esc_url( $update_plugin->get_activate_url() ),
				esc_html__( 'Activate' )
			);
		}

		if ( ! $update_plugin->is_active() ) {
			$this->errors[] = new WP_Error( 'plugin_required', sprintf( '%s %s', $notice, $notice_action ?? '' ), [ 'type' => 'warning' ] );
		}

		foreach ( $this->errors as $error ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $error->get_error_data()['type'] ?? 'error' ),
				wp_kses_post( $error->get_error_message() )
			);
		}
	}
}
