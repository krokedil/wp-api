# Changelog

All notable changes of wp-api are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Fixed
* Fixed masking rules under `body` never matching. The request body was decoded into an object, and the masking only walks arrays, so every rule configured under `body` silently did nothing. It is now decoded into an array, and still logged as a structure rather than an escaped string.
* Fixed falsy values being skipped by the masking. `0`, `'0'` and `false` were sent, and are now masked like any other value.
* Fixed masking rules being case sensitive. Header names are case insensitive by spec, so a rule for `Authorization` no longer misses a header sent as `authorization`.

### Security
* Masking now fails closed. A failure while masking used to return the unmasked data, which logged everything it was meant to hide. The section is now logged as `[MASKING FAILED]` instead.
* Added a key name pass over the finished log entry, masking values by key name wherever they appear, including in free text and in shapes no rule describes. Consuming plugins widen the list with `KeyMasker::add_keys()`.
* Stack trace arguments are masked before they are encoded when `extended_debugging` is on. Frame arguments are positional, so this is a mitigation and not coverage, see the docblock on `Logger::get_caller_string()`.

### Added
* Added an allow list to the masking configuration. The reserved `keep` key masks every key in a container that it does not name, so a field the provider adds later is masked by default.
* Added `[MISSING]` alongside `[REDACTED]`, so a support log can tell a credential that was sent from one that never made it into the request.
* Added `mask_request_url()` for APIs that address a resource by a token in the path. It returns the URL unchanged by default, and an override that throws costs the log line and not the API call.
* Added `$argument_fields_to_mask`, replacing the hardcoded `username` and `password` with configuration that defaults to those two.
* The `$masked_fields` constructor argument now merges into nested configuration instead of replacing it, and takes an `arguments` key.
* Added PHPUnit, PHPCS and PHPStan with `composer test`, `composer phpcs` and `composer phpstan`, and GitHub Actions workflows for pull requests.

### Changed
* `sanitize_request_args()` is deprecated and now delegates to the shared masking, keeping its behaviour.

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
