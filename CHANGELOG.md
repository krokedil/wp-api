# Changelog

All notable changes of wp-api are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
* Added configurable masking for the request, the response and the request arguments, replacing the hardcoded Authorization header and `username`/`password` handling. Rules are declared as `$request_fields_to_mask`, `$response_fields_to_mask` and `$argument_fields_to_mask`, or passed as the `$masked_fields` constructor argument, where they merge into what the class already declares.
* Added an allow list to the masking configuration. The reserved `keep` key masks every key in a container that it does not name, so a field the provider adds later is masked by default.
* Added `Krokedil\WpApi\FieldMasker`, so a plugin that logs from outside a `Request` subclass can apply the same configured rules.
* Added `mask_request_url()` for APIs that address a resource by a token in the path. It returns the URL unchanged by default, and an override that throws costs the log line and not the API call.
* Added PHPUnit, PHPCS and PHPStan with `composer test`, `composer phpcs` and `composer phpstan`, and GitHub Actions workflows for pull requests.

### Changed
* `[MISSING]` now tells an empty value from a sent one on every masked field, rather than only on the Authorization header.
* Masking no longer decides by length whether a header holds a token. Any value a rule names is replaced, whatever its length.
* Data nested deeper than twelve levels is masked rather than walked.

### Deprecated
* `sanitize_request_args()` is deprecated and delegates to the configured masking, keeping its behaviour.

### Security
* The response body is now masked. It was logged in full, so every field the provider returned, customer details included, reached the log.
* Stack trace arguments are now masked. With extended debugging on, every value passed to every caller was json encoded into the log as it was. Frame arguments are positional, so a bare token with no key name still survives unless its shape gives it away.
* Added a key name pass over the finished log entry. `Krokedil\WpApi\KeyMasker` masks by key name wherever it appears, and by shape for an Authorization value, a JWT and a standalone base64 blob, catching what no rule describes. Consuming plugins widen the list with `KeyMasker::add_keys()`.
* Masking fails closed. A section that cannot be masked is logged as `[MASKING FAILED]` rather than in the clear.

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
