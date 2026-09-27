# Changelog

All notable changes to `cbagdawala/innlogger-codeigniter4` are listed here. Versions follow [semantic versioning](https://semver.org).

## 1.0.1 (2026-09-27)

- Released under the MIT licence and published on Packagist: install with a plain `composer require`, no repository entry or token needed.

## 1.0.0 (2026-09-26)

First release.

- `service('innlogger')` with all seven severities, `exception()` and `log()`.
- Optional `log_message()` handler and `ReportingExceptionHandler` that keeps CodeIgniter's error page.
- Request context capture (URI, method, route, request ID, user ID).
- Heartbeat, plus the `innlogger:test`, `innlogger:status` and `innlogger:heartbeat` spark commands.
- HMAC-SHA256 signed requests, threshold filtering, bounded retries that reuse the event ID, 429 cooldown.
- Fail-silent transport with short timeouts and a recursion guard; the secret is kept out of dumps and serialization.
- Recursive, case-insensitive key redaction and value masking of messages and stack traces.
