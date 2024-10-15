<?php

use PHPUnit\Framework\TestCase;
use WP_Elevator\Update_Client\Update_Client;

class Update_Client_Test extends TestCase {
	public function test_plugin_available() {
		$this->assertTrue( class_exists( Update_Client::class ), 'Update client class exists' );
	}
}
