<?php
/**
 * Tests for the User-Agent header built by Request.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase {

	private function request( $config = array() ) {
		$config = array_merge(
			array(
				'base_url'       => 'https://api.example.test',
				'plugin_version' => '1.0.3',
			),
			$config
		);
		return new TestRequest( $config );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_api_test_filters'] );
		parent::tearDown();
	}

	public function test_the_short_name_is_used_when_no_user_agent_name_is_set() {
		$user_agent = $this->request( array( 'plugin_short_name' => 'LP' ) )->user_agent();

		$this->assertSame(
			'WordPress/6.9.1; https://shop.example.test - WooCommerce: 10.5.2 - LP: 1.0.3 - PHP Version: ' . phpversion() . ' - Krokedil',
			$user_agent
		);
	}

	public function test_the_user_agent_name_wins_over_the_short_name() {
		$user_agent = $this->request(
			array(
				'plugin_short_name'      => 'LP',
				'plugin_user_agent_name' => 'LedyerPayments',
			)
		)->user_agent();

		$this->assertStringContainsString( ' - LedyerPayments: 1.0.3 - ', $user_agent );
		$this->assertStringNotContainsString( ' - LP: ', $user_agent );
	}

	public function test_the_default_name_is_used_when_neither_is_set() {
		$this->assertStringContainsString( ' - KAPI: 1.0.3 - ', $this->request()->user_agent() );
	}

	public function test_the_filter_gets_the_request_url() {
		$filter_url = null;

		$GLOBALS['wp_api_test_filters']['http_headers_useragent'] = function ( $user_agent, $url = null ) use ( &$filter_url ) {
			$filter_url = $url;
			return $user_agent;
		};

		$this->request()->user_agent();

		$this->assertSame( 'https://api.example.test/', $filter_url );
	}
}
