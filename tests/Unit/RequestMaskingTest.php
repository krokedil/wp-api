<?php
/**
 * Tests for the configured masking in Request.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\KeyMasker;
use Krokedil\WpApi\Logger;
use PHPUnit\Framework\TestCase;

class RequestMaskingTest extends TestCase {

	private function request( $class = TestRequest::class, $masked_fields = array() ) {
		return new $class( array( 'base_url' => 'https://example.test' ), array(), array(), $masked_fields );
	}

	protected function tearDown(): void {
		KeyMasker::reset_keys();
		Logger::$log = null;
		unset( $GLOBALS['wp_api_test_response'] );
		parent::tearDown();
	}

	/** Item 1: everything in an allow listed container but the kept keys is masked. */
	public function test_an_allow_list_masks_everything_it_does_not_name() {
		$masked = $this->request()->sanitize(
			array(
				'billing_address' => array(
					'postal_code' => '12345',
					'city'        => 'Stockholm',
					'email'       => 'ada@example.test',
					'phone'       => '070000000',
					'given_name'  => 'Ada',
				),
			),
			array(
				'billing_address' => array( 'keep' => array( 'postal_code', 'city' ) ),
			)
		);

		$this->assertSame( '12345', $masked['billing_address']['postal_code'] );
		$this->assertSame( 'Stockholm', $masked['billing_address']['city'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['billing_address']['email'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['billing_address']['phone'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['billing_address']['given_name'] );
	}

	/** Item 1: a field the provider adds later is masked without anyone touching the rules. */
	public function test_an_allow_list_masks_a_field_nobody_configured() {
		$masked = $this->request()->sanitize(
			array(
				'customer' => array(
					'type'          => 'person',
					'date_of_birth' => '1815-12-10',
				),
			),
			array( 'customer' => array( 'keep' => array( 'type' ) ) )
		);

		$this->assertSame( 'person', $masked['customer']['type'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['customer']['date_of_birth'] );
	}

	/** Item 1: a kept key that another rule also describes gets both, in either declaration order. */
	public function test_an_allow_list_and_a_sibling_rule_do_not_depend_on_declaration_order() {
		$data = array(
			'customer' => array(
				'type'            => 'person',
				'date_of_birth'   => '1815-12-10',
				'billing_address' => array(
					'city'  => 'Stockholm',
					'email' => 'ada@example.test',
				),
			),
		);

		$keep_first = array(
			'customer'        => array( 'keep' => array( 'type', 'billing_address' ) ),
			'billing_address' => array( 'email' ),
		);
		$rule_first = array(
			'billing_address' => array( 'email' ),
			'customer'        => array( 'keep' => array( 'type', 'billing_address' ) ),
		);

		$a = $this->request()->sanitize( $data, $keep_first );
		$b = $this->request()->sanitize( $data, $rule_first );

		$this->assertSame( $a, $b );
		$this->assertSame( 'person', $a['customer']['type'] );
		$this->assertSame( KeyMasker::REDACTED, $a['customer']['date_of_birth'] );
		$this->assertSame( 'Stockholm', $a['customer']['billing_address']['city'] );
		$this->assertSame( KeyMasker::REDACTED, $a['customer']['billing_address']['email'] );
	}

	/** Item 1: a container inside a list is masked exactly like one at the root, with no extra rule. */
	public function test_a_container_inside_a_list_is_masked_like_one_at_the_root() {
		$address = array(
			'postal_code' => '12345',
			'email'       => 'ada@example.test',
		);

		$masked = $this->request()->sanitize(
			array(
				'billing_address' => $address,
				'captures'        => array(
					array( 'billing_address' => $address ),
					array( 'billing_address' => $address ),
				),
			),
			array( 'billing_address' => array( 'keep' => array( 'postal_code' ) ) )
		);

		$this->assertSame( $masked['billing_address'], $masked['captures'][0]['billing_address'] );
		$this->assertSame( $masked['billing_address'], $masked['captures'][1]['billing_address'] );
		$this->assertSame( '12345', $masked['captures'][0]['billing_address']['postal_code'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['captures'][0]['billing_address']['email'] );
	}

	/** Item 1: the allow list works at the root of a rule set too. */
	public function test_an_allow_list_works_at_the_root() {
		$masked = $this->request()->sanitize(
			array(
				'method'  => 'POST',
				'headers' => array( 'Authorization' => 'Basic dGVzdDp0ZXN0' ),
			),
			array( 'keep' => array( 'method' ) )
		);

		$this->assertSame( 'POST', $masked['method'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['headers'] );
	}

	/** A named field with a non array value names the field to mask. */
	public function test_a_named_field_can_be_masked_without_a_list() {
		$masked = $this->request()->sanitize(
			array( 'attachment' => array( 'body' => 'a lot of data' ) ),
			array( 'attachment' => 'mask' )
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['attachment'] );
	}

	/** Item 2: a masking failure costs the request section, it never leaks it. */
	public function test_mask_request_args_fails_closed() {
		$request = $this->request( ThrowingRequest::class )->set_request_fields( array( 'headers' => array( 'Authorization' ) ) );

		$masked = $request->mask_request( array( 'headers' => array( 'Authorization' => 'Basic secret' ) ) );

		$this->assertSame( KeyMasker::FAILED, $masked );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $masked ) );
	}

	/** Item 2: the same for the response section. */
	public function test_mask_response_fails_closed() {
		$request = $this->request( ThrowingRequest::class )->set_response_fields( array( 'client_token' ) );

		$masked = $request->mask_the_response( array( 'client_token' => 'a-real-token' ) );

		$this->assertSame( KeyMasker::FAILED, $masked );
		$this->assertStringNotContainsString( 'a-real-token', wp_json_encode( $masked ) );
	}

	/** Item 2 and 10: and for the arguments section. */
	public function test_mask_arguments_fails_closed() {
		$request = $this->request( ThrowingRequest::class );

		$masked = $request->mask_the_arguments( array( 'password' => 'hunter2' ) );

		$this->assertSame( KeyMasker::FAILED, $masked );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $masked ) );
	}

	/** Item 2: a config that cannot even be read fails closed too, on every section. */
	public function test_an_unreadable_config_fails_closed() {
		$request = $this->request( UnreadableConfigRequest::class );

		$this->assertSame( KeyMasker::FAILED, $request->mask_request( array( 'headers' => array( 'Authorization' => 'Basic secret' ) ) ) );
		$this->assertSame( KeyMasker::FAILED, $request->mask_the_response( array( 'client_token' => 'a-real-token' ) ) );
		$this->assertSame( KeyMasker::FAILED, $request->mask_the_arguments( array( 'password' => 'hunter2' ) ) );
	}

	/** Item 3: rules under body match the json string that was really sent. */
	public function test_the_body_is_masked_when_it_arrives_as_a_json_string() {
		$request = $this->request()->set_request_fields(
			array( 'body' => array( 'billing_address' => array( 'keep' => array( 'city' ) ) ) )
		);

		$masked = $request->mask_request(
			array(
				'body' => wp_json_encode(
					array(
						'billing_address' => array(
							'city'  => 'Stockholm',
							'email' => 'ada@example.test',
						),
					)
				),
			)
		);

		// Logged as a structure, not as an escaped string, which is how it has always read.
		$this->assertIsArray( $masked['body'] );
		$this->assertSame( 'Stockholm', $masked['body']['billing_address']['city'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['body']['billing_address']['email'] );
	}

	/** A body that is not json is left exactly as it was sent. */
	public function test_a_body_that_is_not_json_is_left_alone() {
		$masked = $this->request()->mask_request( array( 'body' => 'order_id=123&status=captured' ) );

		$this->assertSame( 'order_id=123&status=captured', $masked['body'] );
	}

	/** Item 3: and when it arrives already decoded. */
	public function test_the_body_is_masked_when_it_arrives_as_an_array() {
		$request = $this->request()->set_request_fields( array( 'body' => array( 'billing_address' => array( 'email' ) ) ) );

		$masked = $request->mask_request(
			array( 'body' => array( 'billing_address' => array( 'email' => 'ada@example.test' ) ) )
		);

		$this->assertIsArray( $masked['body'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['body']['billing_address']['email'] );
	}

	/** Item 4: a falsy value was still a value that was sent. */
	public function test_falsy_values_are_masked() {
		$masked = $this->request()->sanitize(
			array(
				'zero'         => 0,
				'zero_string'  => '0',
				'false_value'  => false,
				'empty_string' => '',
				'null_value'   => null,
			),
			array( 'zero', 'zero_string', 'false_value', 'empty_string', 'null_value' )
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['zero'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['zero_string'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['false_value'] );
		$this->assertSame( KeyMasker::MISSING, $masked['empty_string'] );
		$this->assertSame( KeyMasker::MISSING, $masked['null_value'] );
	}

	/** Item 5: a credential that was sent reads differently from one that never made it in. */
	public function test_the_two_placeholders_are_used_for_present_and_empty() {
		$masked = $this->request()->sanitize(
			array(
				'headers' => array(
					'Authorization' => 'Basic dGVzdDp0ZXN0',
					'X-Token'       => '',
				),
			),
			array( 'headers' => array( 'Authorization', 'X-Token' ) )
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['headers']['Authorization'] );
		$this->assertSame( KeyMasker::MISSING, $masked['headers']['X-Token'] );
	}

	/** Item 6: header names are case insensitive by spec, so the rules have to be too. */
	public function test_key_matching_is_case_insensitive() {
		$masked = $this->request()->sanitize(
			array( 'HEADERS' => array( 'authorization' => 'Basic dGVzdDp0ZXN0' ) ),
			array( 'headers' => array( 'Authorization' ) )
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['HEADERS']['authorization'] );
	}

	/** Item 5 and 6: the deprecated method keeps working, on the shared engine. */
	public function test_the_deprecated_sanitize_request_args_delegates() {
		$request = $this->request();

		$present = $request->deprecated_sanitize( array( 'headers' => array( 'authorization' => 'Basic dGVzdDp0ZXN0' ) ) );
		$empty   = $request->deprecated_sanitize( array( 'headers' => array( 'Authorization' => '' ) ) );

		$this->assertSame( KeyMasker::REDACTED, $present['headers']['authorization'] );
		$this->assertSame( KeyMasker::MISSING, $empty['headers']['Authorization'] );
	}

	/** Item 9: the seam returns the URL unchanged by default. */
	public function test_mask_request_url_is_a_no_op_by_default() {
		$url = 'https://example.test/authorizations/a-real-token/order';

		$this->assertSame( $url, $this->log_one_request( TestRequest::class )['request_url'] );
		$this->assertStringContainsString( 'a-real-token', $this->log_one_request( TestRequest::class )['request_url'] );
		unset( $url );
	}

	/** Item 9: a child class can mask a token out of the path. */
	public function test_mask_request_url_can_be_overridden() {
		$entry = $this->log_one_request( UrlMaskingRequest::class );

		$this->assertSame( 'https://example.test/authorizations/[REDACTED]/order', $entry['request_url'] );
	}

	/** Item 9: an override that throws must not kill the API call, and must not leak the URL. */
	public function test_a_url_override_that_throws_redacts_and_lets_the_request_finish() {
		$GLOBALS['wp_api_test_response'] = array(
			'body'     => '{"order_id":"123"}',
			'response' => array( 'code' => 200 ),
		);

		Logger::$log           = new \WC_Logger();
		$request               = new ThrowingUrlRequest( array( 'base_url' => 'https://example.test' ) );
		$request->endpoint     = 'authorizations/a-real-token/order';
		$request->request_args = array( 'method' => 'POST' );

		$result = $request->request();

		$this->assertSame( array( 'order_id' => '123' ), $result );

		$entry = json_decode( Logger::$log->entries[0]['message'], true );
		$this->assertSame( KeyMasker::FAILED, $entry['request_url'] );
		$this->assertStringNotContainsString( 'a-real-token', Logger::$log->entries[0]['message'] );
	}

	/** Item 10: the arguments masking is configuration, not two hardcoded names. */
	public function test_the_argument_fields_are_configurable() {
		$default = $this->request()->mask_the_arguments(
			array(
				'username' => 'ada',
				'password' => 'hunter2',
				'order_id' => '123',
			)
		);

		$this->assertSame( KeyMasker::REDACTED, $default['username'] );
		$this->assertSame( KeyMasker::REDACTED, $default['password'] );
		$this->assertSame( '123', $default['order_id'] );

		$extra = $this->request( TestRequest::class, array( 'arguments' => array( 'order_id' ) ) )->mask_the_arguments(
			array(
				'username' => 'ada',
				'order_id' => '123',
			)
		);

		$this->assertSame( KeyMasker::REDACTED, $extra['username'] );
		$this->assertSame( KeyMasker::REDACTED, $extra['order_id'] );
	}

	/** Item 10: the constructor argument can express an allow list, not just extra removals. */
	public function test_the_constructor_argument_can_express_an_allow_list() {
		$request = $this->request(
			TestRequest::class,
			array(
				'request' => array(
					'body' => array( 'billing_address' => array( 'keep' => array( 'city' ) ) ),
				),
			)
		);

		$masked = $request->mask_request(
			array(
				'headers' => array( 'Authorization' => 'Basic dGVzdDp0ZXN0' ),
				'body'    => array(
					'billing_address' => array(
						'city'  => 'Stockholm',
						'email' => 'ada@example.test',
					),
				),
			)
		);

		// The configured default is kept, and the allow list is added to it.
		$this->assertSame( KeyMasker::REDACTED, $masked['headers']['Authorization'] );
		$this->assertSame( 'Stockholm', $masked['body']['billing_address']['city'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['body']['billing_address']['email'] );
	}

	/** An allow listed value is not exempt from the depth guard. */
	public function test_an_allow_listed_value_is_still_depth_guarded() {
		$deep = 'the deepest value';
		for ( $i = 0; $i < KeyMasker::MAX_DEPTH + 3; $i++ ) {
			$deep = array( 'level' => $deep );
		}

		$entry = KeyMasker::mask(
			$this->request()->sanitize(
				array( 'order' => array( 'nested' => $deep ) ),
				array( 'order' => array( 'keep' => array( 'nested' ) ) )
			)
		);

		$this->assertStringNotContainsString( 'the deepest value', wp_json_encode( $entry ) );
	}

	/** A value the configured pass masked keeps its placeholder through the key name pass. */
	public function test_a_configured_placeholder_survives_the_key_name_pass() {
		$masked = $this->request()->sanitize(
			array( 'headers' => array( 'Authorization' => '' ) ),
			array( 'headers' => array( 'Authorization' ) )
		);

		$entry = KeyMasker::mask( $masked );

		$this->assertSame( KeyMasker::MISSING, $entry['headers']['Authorization'] );
	}

	/**
	 * Run a real request through the double and return the decoded log entry.
	 *
	 * @param string $class The request class to use.
	 * @return array
	 */
	private function log_one_request( $class ) {
		$GLOBALS['wp_api_test_response'] = array(
			'body'     => '{}',
			'response' => array( 'code' => 200 ),
		);

		Logger::$log           = new \WC_Logger();
		$request               = new $class( array( 'base_url' => 'https://example.test' ) );
		$request->endpoint     = 'authorizations/a-real-token/order';
		$request->request_args = array( 'method' => 'POST' );
		$request->request();

		return json_decode( Logger::$log->entries[0]['message'], true );
	}
}
