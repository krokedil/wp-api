# Krokedil/WP-Api
 Composer Library for Krokedils WordPress API class. Implements the WC_Logger class for logging requests and responses when they are made.

### Requirements

- PHP >=7.1

### Installation

Require this package as a development dependency with [Composer](https://getcomposer.org).

For now this repo is private, so you need to add the following to your composer.json file:

```json
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:krokedil/woocommerce.git"
    }
  ],
```

This also requires you to have a valid SSH key added to your GitHub account, and that you have access to the repository. You can read more about this in the [Composer documentation](https://getcomposer.org/doc/05-repositories.md#using-private-repositories).

### Usage

The library contains a base class that contains the basic functionality for making requests following the standard that Krokedil has internally for our plugins. Such as setting up the headers, building the request URL, processing the request response, as well as logging the request and response data using the [WC_Logger](https://woocommerce.github.io/code-reference/classes/WC-Logger.html) class.


To make a simple request, start with creating a class that  extends the base abstract class Krokedil\WpApi\Request

```php
use Krokedil\WpApi\Request;

class MyRequest extends Request {

}
```
To configure the request class properly, you will need to add a constructor to the class, and call the parent constructor with the following arguments:

`$config: array` - An array containing the following.
- `slug: string` - The slug of the plugin making the request. Should be the same as the plugin slug in WordPress.org, will also be used as the log file name.
- `plugin_version: string` - The version of the plugin making the request. Will be used in the User-Agent header, and in the log data. Should always match the version of the plugin making the request.
- `plugin_short_name: string` - A short name for the plugin making the request. Will be used in the User-Agent header, since the slug can be a long string its nice to have a shorter name in some cases.
- `logging_enabled: bool` - A boolean value indicating if logging is enabled or not. If logging is enabled, the request and response data will be logged using the WC_Logger class.
- `extended_debugging: bool` - A boolean value indicating if extended debugging is enabled or not. If extended debugging is enabled, the stack trace for the logs will contain a lot more information. For example the property values passed to each caller.
- `base_url: string` - The base URL for the request. This will be the URL that the request will be made to. The request URL will be built by appending the path to the base URL. The path is set in the `$arguments` array.

`$settings: array` - An array containing the settings for the plugin making the request. For example an array from the WooCommerce settings API, but any array will do. These will be set to the class property `$this->settings`. The settings will be available in the request class, and can be used by the child class to adapt things as needed, such as what to pass in the request body or headers.

`$arguments: array` - An array containing the arguments for the request. These will be set to the class property `$this->arguments`. The arguments will be available in the request class, and can be used by the child class to adapt things as needed, just like the settings, but these are more likely to change between requests, since they will be passed by the caller of the class when making the request.

```php
use Krokedil\WpApi\Request;

class MyRequest extends Request {
public function __construct( $arguments ) {
        $settings = get_option( 'my_plugin_settings' );
		$config   = array(
			'slug'               => MY_PLUGN_SLUG_DEFINITION,
			'plugin_version'     => MY_PLUGN_VERSION_DEFINITION,
			'plugin_short_name'  => 'MPSN',
			'logging_enabled'    => 'yes' === $settings['logging'], // Example showing how to use a setting to enable logging.
			'extended_debugging' => 'yes' === $settings['extended_logging'], // Example showing how to use a setting to enable extended debugging.
			'base_url'           => 'https://example.com',
		);

		parent::__construct( $config, $settings, $arguments );

        // If you need to do any additional setup, do it here, and set the properties you need in your own child class.
        // The child class can add whatever extra methods or properties it needs for its own usecase.
    }
}
```
The abstract class contains three abstract methods that needs to be implemented by the child class. These are:
`calculate_auth()` - This should return a string that should be the value of the authentication header. For example if you need to do a calculation for a basic auth, you can do that here by returning the string value.
```php
protected function calculate_auth() {
    $base64 = base64_encode( $this->settings['username'] . ':' . $this->settings['password'] );
    return "Basic $base64";
}
```
Use whatever method you need to calculate the auth string, based on what API you are working with. If your api needs a basic auth, then do that. If it needs a more complex JWT token, you can return that instead. The only requirement is that the method returns a string, and that string has to be a valid value for whatever `Autentication` header is required by the API you are working with.

`get_request_args()` - This should return an array with the arguments for the request. This array will be passed to the `wp_remote_request()` function, [and the array should follow the default args for the WP_HTTP::request method](https://developer.wordpress.org/reference/classes/WP_Http/request/).
```php
protected function get_request_args() {
    return array(
        'headers'    => $this->get_request_headers(),
        'user-agent' => $this->get_user_agent(),
        'method'     => 'GET',
    );
}
```
If you need to add a body, that can be done by adding the `body` key with the value of the body, generally as a json encoded string.

`get_error_message( $response )` - Since all APIs handle errors differently, this method should parse the response body and extract any error messages that might be present. This should then return a WP_Error object with the error message, and any other data that might be useful for debugging.

### Recommendations
The recommended the class that extends the library class an abstract class that can also be extended by more direct or concrete implementations. This will alow you to have a lot of different request classes that can be used for different purposes, but still share the same base functionality. For example setting the same config to be reused for multiple requests, since most likely they will always be the same, or define the method to calculate the auth just once in your own base abstract class.

It also lets you go further with the abstraction. For example create a abstract class that extends the library class, and then extend that further with abstract classes for each HTTP method you will be using. Such as a GET request, a POST request, a PUT request, etc. This will let you easily setup the request arguments for each method, and you can share the same base functionality for all of them.

Or create a concrete class for each different object type that you will be making requests on. Like Order, Session, Customer... Then you can have a single helper method to generate the body for each, and have the code be easily maintained as well.

Depending on your need you can go as far as you want with the abstraction, but the library class is designed to be as flexible as possible, and to allow you to do whatever you need to do. And if you need to override any of the non-abstract methods in the base class, you can do so in your own child class as needed, if for example you need to add a request header, or change the content type.

### Example usage of the library using further abstraction.
```php
use Krokedil\WpApi\Request;

// First setup a base request class that extends the library class, and does all the basic configurations.
abstract class MyBaseRequest extends Request {
    public function __construct( $arguments ) {
    $settings = get_option( 'my_plugin_settings' );
		$config   = array(
			'slug'               => MY_PLUGN_SLUG_DEFINITION,
			'plugin_version'     => MY_PLUGN_VERSION_DEFINITION,
			'plugin_short_name'  => 'MPSN',
			'logging_enabled'    => 'yes' === $settings['logging'],
			'extended_debugging' => 'yes' === $settings['extended_logging'],
			'base_url'           => 'https://example.com',
		);

		parent::__construct( $config, $settings, $arguments );

        // Lets set the username and password here, then we won't have to use the settings property over and over in the code if we want to use them again.
        $this->username = $settings['username'];
        $this->password = $settings['password'];
    }

    protected function calculate_auth() {
        $base64 = base64_encode( $this->username . ':' . $this->password );
        return "Basic $base64";
    }

    protected function get_error_message( $response ) {
        $error_message = '';
		if ( null !== json_decode( $response['body'], true ) ) {
			foreach ( json_decode( $response['body'], true )['error_messages'] as $error ) {
				$error_message = "$error_message $error";
			}
		}
		$code          = wp_remote_retrieve_response_code( $response );
		$error_message = empty( $error_message ) ? $response['response']['message'] : $error_message;
		return new WP_Error( $code, $error_message );
    }
}

// Now lets create a abstract class that extends the base class, and implements the get_request_args method for a GET request.
abstract class MyGetRequest extends MyBaseRequest {
    protected function get_request_args() {
        return array(
            'headers'    => $this->get_request_headers(),
            'user-agent' => $this->get_user_agent(),
            'method'     => 'GET',
        );
    }
}

// Create a concrete class for each different endpoint that we want to use.
class MyGetOrderRequest extends MyGetRequest {
    public function __construct( $arguments ) {
        parent::__construct( $arguments );
        $this->endpoint = 'orders'; // The library will automatically combine any base url with the endpoint to create the full url. So if the base url is https://example.com, and the endpoint is orders, then the full url will be https://example.com/orders.
    }
}

class MyGetCustomerRequest extends MyGetRequest {
    public function __construct( $arguments ) {
        parent::__construct( $arguments );
        $this->endpoint = 'customers'; // The library will automatically combine any base url with the endpoint to create the full url. So if the base url is https://example.com, and the endpoint is customers, then the full url will be https://example.com/customers.
    }
}

// Now we can use the request classes like this:
$get_orders = new MyGetOrderRequest();
$order = $get_orders->request();

$get_customers = new MyGetCustomerRequest();
$customers = $get_customers->request();
```
