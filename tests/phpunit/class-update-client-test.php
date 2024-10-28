<?php

use PHPUnit\Framework\TestCase;
use WPElevator\Update_Client\Plugin_Update;

class Update_Client_Test extends TestCase {
	public function test_plugin_available() {
		$this->assertTrue( class_exists( Plugin_Update::class ), 'Update client class exists' );
	}
}
