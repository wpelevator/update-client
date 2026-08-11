<?php

use WPElevator\Update_Client\Package_Signature;

class Package_Signature_Test extends WP_UnitTestCase {

	const PUBLIC_KEY = 'APBHggGnhrL1VxZ71NI3ElbWTpdEOljwnPh+gkDKxRc=';

	public function test_inactive_until_url_registered() {
		$signature = new Package_Signature( self::PUBLIC_KEY );
		$signature->init();

		$this->assertNotContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [ 'wordpress.org' ] ),
			'No hosts require signature verification before a package URL is registered'
		);

		$this->assertTrue(
			apply_filters( 'wp_signature_softfail', true, 'https://updates.example.com/download.zip' ),
			'Signature errors can still be ignored for hosts without enforced verification'
		);

		$this->assertNotContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'The public key is not trusted before a package URL is registered for verification'
		);
	}

	public function test_enforces_signature_checks_for_registered_url() {
		$signature = new Package_Signature( self::PUBLIC_KEY );
		$signature->init();
		$signature->enforce_for_url( 'https://updates.example.com/download.zip' );

		$hosts = apply_filters( 'wp_signature_hosts', [ 'wordpress.org' ] );

		$this->assertContains(
			'updates.example.com',
			$hosts,
			'Package host is added to the hosts with enforced signature verification'
		);

		$this->assertContains(
			'wordpress.org',
			$hosts,
			'WP core signature hosts are preserved'
		);

		$this->assertFalse(
			apply_filters( 'wp_signature_softfail', true, 'https://updates.example.com/download.zip' ),
			'Signature errors abort the install for the registered package host'
		);

		$this->assertTrue(
			apply_filters( 'wp_signature_softfail', true, 'https://other.example.com/download.zip' ),
			'Signature errors can still be ignored for other hosts'
		);

		$this->assertContains(
			self::PUBLIC_KEY,
			apply_filters( 'wp_trusted_keys', [] ),
			'The known public key is added to the trusted signing keys'
		);

		$this->assertCount(
			1,
			apply_filters( 'wp_trusted_keys', [ self::PUBLIC_KEY ] ),
			'The known public key is not duplicated in the trusted signing keys'
		);
	}

	public function test_wp_core_verifies_file_signed_with_trusted_key() {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The PHP Sodium extension is not available.' );
		}

		if ( ! function_exists( 'verify_file_signature' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$keypair = sodium_crypto_sign_keypair();

		$package_file = wp_tempnam( 'update-client-package' );
		file_put_contents( $package_file, 'package-contents' );

		// Sign the raw SHA-384 digest of the file as expected by verify_file_signature().
		$package_signature = base64_encode(
			sodium_crypto_sign_detached(
				hash_file( 'sha384', $package_file, true ),
				sodium_crypto_sign_secretkey( $keypair )
			)
		);

		$signature = new Package_Signature( base64_encode( sodium_crypto_sign_publickey( $keypair ) ) );
		$signature->init();
		$signature->enforce_for_url( 'https://updates.example.com/download.zip' );

		$this->assertTrue(
			verify_file_signature( $package_file, $package_signature ),
			'WP core verifies the file signature using the trusted public key'
		);

		$this->assertWPError(
			verify_file_signature( $package_file, base64_encode( str_repeat( 'a', SODIUM_CRYPTO_SIGN_BYTES ) ) ),
			'WP core rejects a file with an invalid signature'
		);

		unlink( $package_file );
	}
}
