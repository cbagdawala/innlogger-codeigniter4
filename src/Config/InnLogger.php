<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Config;

use Closure;
use CodeIgniter\Config\BaseConfig;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Severity;

/**
 * InnLogger configuration.
 *
 * Values come from, in order of precedence:
 *  1. the INNLOGGER_* environment variables (spec 06: INNLOGGER_URL, INNLOGGER_API_KEY, ...);
 *  2. CodeIgniter's own .env overrides (innlogger.url, innlogger.apiKey, ...);
 *  3. the defaults below, or an app/Config/InnLogger.php that extends this class.
 */
class InnLogger extends BaseConfig
{
    /**
     * INNLOGGER_* variable => property.
     */
    public const ENV_MAP = [
        'INNLOGGER_ENABLED' => 'enabled',
        'INNLOGGER_URL' => 'url',
        'INNLOGGER_API_KEY' => 'apiKey',
        'INNLOGGER_API_SECRET' => 'apiSecret',
        'INNLOGGER_LOG_LEVEL' => 'logLevel',
        'INNLOGGER_TIMEOUT' => 'timeout',
        'INNLOGGER_CONNECT_TIMEOUT' => 'connectTimeout',
        'INNLOGGER_ENVIRONMENT' => 'environment',
        'INNLOGGER_APPLICATION' => 'application',
        'INNLOGGER_APP_VERSION' => 'appVersion',
        'INNLOGGER_RETRIES' => 'retries',
        'INNLOGGER_ALLOW_INSECURE' => 'allowInsecure',
        'INNLOGGER_DEBUG' => 'debug',
        'INNLOGGER_AUTO_EXCEPTION' => 'autoException',
        'INNLOGGER_CAPTURE_REQUEST' => 'captureRequestContext',
        'INNLOGGER_REDACT_FIELDS' => 'redactFields',
        'INNLOGGER_MASK_PATTERNS' => 'maskPatterns',
        'INNLOGGER_CATEGORY' => 'category',
    ];

    /** Master switch. false = nothing is sent. */
    public bool $enabled = true;

    /** Portal base URL, e.g. https://logger.example.com (no /api/v1). HTTPS required. */
    public string $url = '';

    public string $apiKey = '';

    public string $apiSecret = '';

    /** Threshold 0-7: send events with level <= this. 0 = send nothing. 2 = CRITICAL + ERROR. */
    public int $logLevel = 2;

    /** Total request timeout in seconds. */
    public float $timeout = 2.0;

    /** Connection timeout in seconds. */
    public float $connectTimeout = 1.0;

    /** Environment name sent with each event. Empty = CodeIgniter's ENVIRONMENT constant. */
    public string $environment = '';

    /** Application name sent with each event (optional). */
    public string $application = '';

    /** Application version, sent in heartbeats and event metadata (optional). */
    public string $appVersion = '';

    /** Extra attempts after a transport failure or HTTP 5xx (0-3). The event_id is reused. */
    public int $retries = 1;

    /** Default event category. Exceptions use "exception"; context['category'] overrides both. */
    public string $category = 'application';

    /** Allow http:// URLs. For local development only. */
    public bool $allowInsecure = false;

    /** Write local diagnostics (never secrets or payloads) through PHP's error_log(). */
    public bool $debug = false;

    /** Report exceptions through ReportingExceptionHandler when it is wired in app/Config/Exceptions.php. */
    public bool $autoException = true;

    /** HTTP status codes whose exceptions are not reported by ReportingExceptionHandler. */
    public array $ignoreStatusCodes = [404];

    /** Capture URI, method, route, request ID and user ID from the current request. */
    public bool $captureRequestContext = true;

    /** Extra keys to redact on top of the built-in list (case-insensitive, recursive). */
    public array $redactFields = [];

    /**
     * Extra masking rules applied to every string value (message, exception
     * message and trace, context, URL), on top of the built-in Bearer/Basic/Digest
     * and "ils_..." rules. Either regex => replacement or a list of regexes
     * (replaced with "[REDACTED]"). INNLOGGER_MASK_PATTERNS takes a JSON list/object.
     * Invalid regexes are ignored.
     *
     * @var array<array-key, string>
     */
    public array $maskPatterns = [];

    /**
     * Resolves the authenticated user's ID. When null, CodeIgniter Shield's
     * auth()->id() is used if Shield is installed. Set it in an app config
     * subclass's constructor, e.g. fn () => session('user_id').
     *
     * @var (Closure(): (int|string|null))|null
     */
    public ?Closure $userIdResolver = null;

    public function __construct()
    {
        parent::__construct();

        foreach (self::ENV_MAP as $variable => $property) {
            $value = self::readEnv($variable);

            if ($value !== null) {
                $this->assign($property, $value);
            }
        }
    }

    /**
     * Dumps (print_r, var_dump, CodeIgniter's debug toolbar) never show the secret.
     */
    public function __debugInfo(): array
    {
        $vars = get_object_vars($this);
        $vars['apiSecret'] = $this->apiSecret === '' ? '' : '[REDACTED]';

        return $vars;
    }

    public static function readEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === null || ! is_scalar($value)) {
            return null;
        }

        return trim((string) $value, " \t\n\r\0\x0B'\"");
    }

    private function assign(string $property, string $value): void
    {
        $current = $this->{$property};

        if (is_bool($current)) {
            $this->{$property} = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        } elseif (is_int($current)) {
            if (is_numeric($value)) {
                $this->{$property} = (int) $value;
            } elseif ($property === 'logLevel' && ($level = Severity::fromMixed($value)) !== null) {
                $this->{$property} = $level; // INNLOGGER_LOG_LEVEL=error also works
            }
        } elseif (is_float($current)) {
            if (is_numeric($value)) {
                $this->{$property} = (float) $value;
            }
        } elseif ($property === 'maskPatterns') {
            $this->maskPatterns = Settings::parsePatterns($value);
        } elseif (is_array($current)) {
            $this->{$property} = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $key): bool => $key !== ''));
        } else {
            $this->{$property} = $value;
        }
    }
}
