<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Log;

use CodeIgniter\Log\Handlers\BaseHandler;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Severity;
use Throwable;

/**
 * Optional CodeIgniter 4 log handler so log_message() calls reach InnLogger.
 *
 * app/Config/Logger.php:
 *   public array $handlers = [
 *       FileHandler::class => [...],
 *       \InnLogger\CodeIgniter4\Log\InnLoggerHandler::class => [
 *           'handles' => ['critical', 'alert', 'emergency', 'error'],
 *       ],
 *   ];
 *
 * The InnLogger threshold still applies on top of 'handles'. The handler
 * always returns true so the next handler runs, and never throws. CodeIgniter
 * interpolates the message before calling handlers, so no context array is sent.
 */
class InnLoggerHandler extends BaseHandler
{
    private ?InnLoggerClient $client;

    public function __construct(array $config = [], ?InnLoggerClient $client = null)
    {
        parent::__construct($config);
        $this->client = $client;
    }

    public function handle($level, $message): bool
    {
        try {
            $severity = Severity::fromMixed((string) $level);

            if ($severity === null || ! Severity::isEventLevel($severity)) {
                return true;
            }

            $client = $this->client ??= service('innlogger');

            if ($client instanceof InnLoggerClient && $client->shouldSend($severity)) {
                $client->log($severity, (string) $message, ['category' => 'log']);
            }
        } catch (Throwable) {
            // Never break the application's logging chain.
        }

        return true;
    }
}
