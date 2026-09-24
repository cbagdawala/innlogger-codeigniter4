# InnLogger SDK for CodeIgniter 4

`cbagdawala/innlogger-codeigniter4` sends application logs and exceptions from a CodeIgniter 4
app to an InnLogger portal. Every request is signed with HMAC-SHA256. Sensitive fields are
redacted before the payload is serialized. The SDK is fail-silent, so a problem on the
InnLogger side never becomes an error in your application.

- PHP 8.1+, CodeIgniter 4.4+, `ext-curl`, `ext-json`
- `service('innlogger')` with all seven severities plus `exception()`
- A severity threshold, where `0` turns sending off
- An optional `log_message()` handler and an optional exception handler that keeps CodeIgniter's normal error page
- Request context: URI, HTTP method, route, request ID, user ID, hostname and app version
- Spark commands: `innlogger:test`, `innlogger:status` and `innlogger:heartbeat`

## Install

```bash
composer require cbagdawala/innlogger-codeigniter4
```

Composer package auto-discovery registers the service and the spark commands. It is on by
default through `Config\Modules::$discoverInComposer`, so you don't need to register anything.

## Configure (.env)

```env
INNLOGGER_URL=https://logger.example.com
INNLOGGER_API_KEY=ilv_xxxxx
INNLOGGER_API_SECRET=ils_xxxxx
INNLOGGER_LOG_LEVEL=2
INNLOGGER_TIMEOUT=2
INNLOGGER_ENABLED=true
INNLOGGER_ENVIRONMENT=production
```

Optional keys:

| Key | Default | Meaning |
|---|---|---|
| `INNLOGGER_CONNECT_TIMEOUT` | `1` | Connection timeout in seconds |
| `INNLOGGER_APPLICATION` | *(empty)* | Application name sent with each event |
| `INNLOGGER_APP_VERSION` | *(empty)* | Sent in heartbeats and in event metadata |
| `INNLOGGER_RETRIES` | `1` | Extra attempts after a network error or HTTP 500/502/503/504 (at most 3) |
| `INNLOGGER_CATEGORY` | `application` | Default event category. Exceptions use `exception`, and `$context['category']` overrides both. |
| `INNLOGGER_REDACT_FIELDS` | *(empty)* | Comma-separated extra keys to redact, e.g. `ssn,pin` |
| `INNLOGGER_MASK_PATTERNS` | *(empty)* | Extra masking regexes as JSON: a list, or an object of regex => replacement |
| `INNLOGGER_AUTO_EXCEPTION` | `true` | Whether `ReportingExceptionHandler` reports exceptions (see below) |
| `INNLOGGER_CAPTURE_REQUEST` | `true` | Capture URI, method, route, request ID and user ID |
| `INNLOGGER_ALLOW_INSECURE` | `false` | Allow an `http://` URL. Use it only for local development. |
| `INNLOGGER_DEBUG` | `false` | Write local diagnostics with PHP's `error_log()` |

If `INNLOGGER_ENVIRONMENT` is empty, CodeIgniter's `ENVIRONMENT` is used. CodeIgniter's own
`.env` style (`innlogger.url`, `innlogger.logLevel`, ...) also works. The `INNLOGGER_*`
variables take precedence over it.

For settings that `.env` can't hold, such as a user-ID resolver, create
`app/Config/InnLogger.php`. `config()` prefers the app class:

```php
<?php

namespace Config;

class InnLogger extends \InnLogger\CodeIgniter4\Config\InnLogger
{
    public array $redactFields = ['ssn', 'iban'];
    public array $ignoreStatusCodes = [404, 405];

    public function __construct()
    {
        parent::__construct();
        $this->userIdResolver = static fn () => session('user_id');
    }
}
```

If no resolver is set and CodeIgniter Shield is installed, `auth()->id()` is used.

Check your setup with:

```bash
php spark innlogger:status   # effective settings; the secret is never printed
php spark innlogger:test     # sends one test event: config validity, reachability, auth, HTTP status, event ID
```

`innlogger:test` ignores the threshold and `INNLOGGER_ENABLED`. It exits with 0 only when the
portal accepts the event.

## Usage

```php
service('innlogger')->critical('System failure', $context);
service('innlogger')->error('Payment failed', $context);
service('innlogger')->warning('Potential problem', $context);
service('innlogger')->notice('Important event', $context);
service('innlogger')->info('Customer created', $context);
service('innlogger')->debug('Debug information', $context);
service('innlogger')->trace('Trace information', $context);
service('innlogger')->exception($exception, $context);           // level ERROR (2)
service('innlogger')->exception($exception, $context, 1);        // or pick a level
service('innlogger')->log('warning', 'Slow API', ['ms' => 950]); // level by name or 1-7
```

Context conventions:

- `$context['category']` (a string) becomes the event's `category`, e.g. `['category' => 'payment']`.
  Every event has a category. Without one it is `exception` for exceptions and
  `INNLOGGER_CATEGORY` (default `application`) for anything else.
- `$context['exception']` (a Throwable, PSR-3 style) becomes the event's `exception` object.
- Everything else is redacted and sent as `context`.

Every call returns a `SendResult`, which you can ignore. It has `status`, `eventId`,
`httpStatus`, `logId`, `attempts`, `error`, `sent()` and `duplicate()`. Nothing is ever thrown.

### Log handler (optional)

To send `log_message()` calls to InnLogger, add the handler in `app/Config/Logger.php`:

```php
public array $handlers = [
    \CodeIgniter\Log\Handlers\FileHandler::class => [/* ... */],
    \InnLogger\CodeIgniter4\Log\InnLoggerHandler::class => [
        'handles' => ['emergency', 'alert', 'critical', 'error'],
    ],
];
```

CodeIgniter levels map as follows: emergency, alert and critical go to 1; error to 2; warning
to 3; notice to 4; info to 5; debug to 6. The InnLogger threshold still applies. The handler
always lets the next handler run. CodeIgniter interpolates the message before a handler sees
it, so these events carry no `context`.

### Exception reporting (optional)

In `app/Config/Exceptions.php`:

```php
use CodeIgniter\Debug\ExceptionHandlerInterface;
use InnLogger\CodeIgniter4\Exceptions\ReportingExceptionHandler;

public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
{
    return new ReportingExceptionHandler($this);
}
```

The handler reports the exception and then hands it to CodeIgniter's normal `ExceptionHandler`,
so users see the same error page. A 5xx is sent as CRITICAL; any other status is sent as ERROR.
The event includes `http_status`. Status codes in `$ignoreStatusCodes` (default `[404]`) are not
reported, and `INNLOGGER_AUTO_EXCEPTION=false` turns reporting off. If you already have a custom
handler, pass it as the second argument: `new ReportingExceptionHandler($this, new MyHandler())`.

If you enable both the log handler (with `critical`) and the exception handler, CodeIgniter's
own `critical` log of each uncaught exception is sent as well. Use one of the two for
exceptions.

### Heartbeat

```bash
*/5 * * * * cd /path/to/app && php spark innlogger:heartbeat >/dev/null 2>&1
```

This command, or `service('innlogger')->heartbeat()`, sends `POST /api/v1/heartbeat` with
`environment`, `hostname` and `application_version`. The portal marks an environment offline
after 10 minutes without a heartbeat.

## Threshold

InnLogger severity: 1 CRITICAL, 2 ERROR, 3 WARNING, 4 NOTICE, 5 INFO, 6 DEBUG, 7 TRACE.
An event is sent when `level <= INNLOGGER_LOG_LEVEL` and the threshold is above 0.

| `INNLOGGER_LOG_LEVEL` | Sends |
|---|---|
| `0` | nothing (the same as disabled) |
| `2` | CRITICAL and ERROR |
| `5` | CRITICAL through INFO |
| `7` | everything |

A level name such as `INNLOGGER_LOG_LEVEL=warning` also works. The threshold controls what is
*transmitted*. Alerting is configured separately, by the portal's notification rules. The
check runs before anything is built, so a filtered call costs almost nothing.

## Failure behaviour

- **Fail-silent.** No public method throws. Network errors, timeouts, non-2xx responses,
  serialization problems and misconfiguration all come back as a `SendResult`
  (`failed`, `invalid_config`, `disabled`, `below_threshold`, `rate_limited` or `reentrant`).
- **Short timeouts.** The defaults are 2 s in total and 1 s to connect. Both are capped at
  30 s. Sending is synchronous, so the timeout is the worst-case delay a log call adds to a
  request, multiplied by the number of attempts.
- **Retry.** `INNLOGGER_RETRIES` (0-3, default 1) retries only network errors and
  HTTP 500/502/503/504, with a 100 ms pause. A retry sends the **same body and `event_id`** with
  a fresh timestamp and nonce, and the same `X-InnLogger-Request-Id`, so the portal stores the
  event once. A duplicate `event_id` returns HTTP 200, which the SDK treats as success
  (`duplicate()` is true). 4xx responses, including 401, 413, 422 and 429, are never retried.
- **Rate limiting (429).** When the portal answers 429, the client stops sending, including
  heartbeats, for the time the portal asks for. That comes from `retry_after` in the body, or
  the `Retry-After` header (seconds or an HTTP date), or is 60 s when neither is present, and
  is kept between 1 s and 1 hour. Calls made during the pause return `rate_limited` at once,
  without any network traffic. The shared `service('innlogger')` holds the pause, so it
  applies to the whole PHP process (one request, or a long-running spark worker).
- **Invalid configuration.** A missing URL, key or secret, or an `http://` URL without
  `INNLOGGER_ALLOW_INSECURE`, means nothing is sent.
- **No recursion.** The SDK never calls `log_message()` or CodeIgniter's logger. A log call made
  while an event is being sent is dropped, for example one made by an error handler reacting
  to something inside the transport.
- **Local diagnostics only when asked.** With `INNLOGGER_DEBUG=true`, delivery problems are
  written with PHP's `error_log()`. Diagnostics hold the path, the event ID and the error,
  never the secret or the payload.
- **Size limits.** The trace is cut to 64 KB. A `context` or `metadata` object over 64 KB is
  replaced by `{"_truncated": true, ...}`. The whole body is kept under the portal's 256 KB limit.

## Security notes

- **HTTPS is required.** `http://` URLs are refused unless `INNLOGGER_ALLOW_INSECURE=true`.
  Use that for local development only. TLS certificates are verified, and redirects are not
  followed.
- **HMAC authentication.** The API secret is never sent. Each request carries
  `X-InnLogger-Key`, `X-InnLogger-Timestamp` (Unix seconds), `X-InnLogger-Nonce` (random,
  unique per attempt), `X-InnLogger-Request-Id` (a fresh UUID per logical send, the same for
  its retries, and unrelated to `event_id`) and
  `X-InnLogger-Signature = hex(HMAC-SHA256(timestamp + "\n" + nonce + "\n" + raw_body, api_secret))`,
  computed over the exact bytes sent, after redaction and masking. The portal rejects
  timestamps older than 5 minutes, so keep the server clock in sync with NTP.
- **The secret stays in memory only.** `Settings`, `Signer`, `Redactor` and the client keep the
  secret inside a closure. So `print_r()`, `var_dump()`, `var_export()`, `json_encode()` and
  `serialize()` of those objects, or of `$client->settings()`, never contain it, and the config
  class hides it from dumps. A serialized `Settings` has no secret, and the client refuses to
  be unserialized. Read the secret only through `Settings::apiSecret()`.
- **Key redaction.** Values under these keys are always replaced with `[REDACTED]`:
  - the spec 09 §5 list: `password`, `password_confirmation`, `token`, `access_token`,
    `refresh_token`, `authorization`, `cookie`, `card_number`, `cvv`, `secret`, `api_secret`
  - the Laravel SDK's additions: `current_password`, `new_password`, `id_token`,
    `proxy_authorization`, `set_cookie`, `cvc`, `client_secret`, `api_key`, `private_key`,
    `csrf_token`, `xsrf_token`, `x_xsrf_token`, `_token`, `x_innlogger_signature`

  Add more with `INNLOGGER_REDACT_FIELDS` or `$redactFields`. Matching is case-insensitive and
  recursive, and treats `-`, `_` and spaces alike, so `X-Api-Secret` matches `api_secret`.
  Sensitive query-string parameters in the captured URL are redacted too.
- **Value masking.** Every string is masked before it is encoded and signed: the message, the
  exception message and trace, context values and the URL. The rules are:
  - `Bearer`, `Basic` and `Digest` credentials become `Bearer [REDACTED]`.
  - Literal `ils_…` InnLogger secrets are masked.
  - The configured API secret is masked wherever it appears, if it is at least 8 characters.
  - Your own rules are applied too, from `$maskPatterns` (regex => replacement, or a list of
    regexes) or `INNLOGGER_MASK_PATTERNS` (JSON), e.g.
    `INNLOGGER_MASK_PATTERNS='{"/\\b\\d{3}-\\d{2}-\\d{4}\\b/":"[SSN]"}'`. Invalid regexes are ignored.
- **Stack traces can contain argument values.** PHP's `getTraceAsString()` includes scalar
  arguments, with strings shortened to 15 characters, unless `zend.exception_ignore_args=On`.
  That is the php.ini-production default. The official Docker images ship without a php.ini,
  so it is off there. Masking catches known formats, but set
  `zend.exception_ignore_args=On` in production to keep argument values out of traces
  entirely.
- **No object dumps.** Objects in `context` are never serialized through their private
  properties. JsonSerializable, `toArray()`, stdClass and Stringable objects use their public
  form. Any other object becomes `"[object Class]"`.
- **Nothing captured implicitly beyond the basics.** Request headers, cookies and bodies are
  never captured. Only the URI, method, route, a request ID (from `X-Request-Id` or
  `X-Correlation-Id`, else generated) and the user ID are captured.
- **Keep the secret out of source control.** Put it in `.env` or the server environment.
  `innlogger:status` prints only whether the secret is set.
- **Treat logs as sensitive data.** Redaction is a safety net, not a licence to log personal
  or payment data. Masking only catches the formats it knows, so don't put secrets in
  exception messages.

## Framework-agnostic core

Everything under `src/Core` works without CodeIgniter:

```php
use InnLogger\CodeIgniter4\Core\{InnLoggerClient, Settings};

$client = new InnLoggerClient(Settings::fromArray([
    'url' => 'https://logger.example.com',
    'api_key' => 'ilv_…',
    'api_secret' => 'ils_…',
    'log_level' => 2,
]));
$client->error('Payment failed', ['order_id' => 42]);
```

Pass your own `TransportInterface` as the second argument, for example a fake in tests. In a
CodeIgniter app's tests, `Services::injectMock('innlogger', $client)` replaces the service.

## Development

Tests use PHPUnit 11 and a fake transport. They also include real HTTP against PHP's built-in
server to check timeouts.

```bash
composer install
./vendor/bin/phpunit
```
