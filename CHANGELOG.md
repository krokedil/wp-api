# Changelog

All notable changes of wp-api are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
* Configurable masking of request and response fields before they are logged. Rules are dot separated paths, `*` matches every key at a level, and the masking reaches inside JSON encoded values such as the request body.
* A `Krokedil\WpApi\Masking` namespace holding the masking classes. They do not use WordPress or WooCommerce, and are unit tested on their own.
* PHPUnit, PHP_CodeSniffer and a `composer test` script for the package.
* `Logger::set_scrubber()`, to widen the list of key names that are masked in every log entry.

### Fixed
* The request body was decoded to an object before masking, so no rule under `body` ever matched.
* Fields passed to the constructor replaced the built in Authorization mask instead of being added to it.
* Header names are matched case insensitively again.
* Values of `0`, `'0'` and `false` were left unmasked.
* `sanitize_request_args()` no longer errors on a request that has no headers.

### Changed
* A masking failure now logs an error marker for that section instead of falling back to the unmasked data.
* The arguments of the stack trace are scrubbed before they are written, so extended debugging no longer dumps credentials that were passed to a caller.

### Deprecated
* `sanitize_request_args()`. Use `mask_request_args()` instead.

### Removed
* `Request::sanitize_field()`, replaced by the masking classes. It was never part of a released version.

------------------
## [1.1.1] - 2023-12-04
### Changed
* Redacted the username and password from the log.

## [1.1.0] - 2023-12-04
### Fixed
* Fixed an issue that would cause a error notice when there was no body set. For example with GET requests.

### Added
* Added sanitation of the auth headers for the requests. This will prevent the auth headers from being logged if its above a certain length. This is to prevent the auth headers from being logged in the logs if they are correct, but still leave information in the logs if they are incorrect.

## [1.0.1] - 2023-02-27

### Fixed

* Fixed a issue where the current WP_Hook method would return a boolean instead of an array.
* Fixed an issue with turning off the log.

## [1.0.0] - 2023-01-13

### Added

* Request base class for all requests. Should be extended by all requests that want to use this library.
* Logger class that will be used to log all requests and responses, along with parameters passed to the request.
* Extended debugging for the logger class that will log the entire stack trace of the request, including the values of the parameters passed to the methods in the stack trace.
