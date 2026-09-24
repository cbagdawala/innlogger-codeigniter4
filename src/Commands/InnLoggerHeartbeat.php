<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;

/**
 * php spark innlogger:heartbeat
 *
 * Sends POST /api/v1/heartbeat. Schedule it from cron every few minutes
 * (the portal marks an environment offline after 10 minutes without one):
 *   * /5 * * * * cd /path/to/app && php spark innlogger:heartbeat >/dev/null 2>&1
 */
class InnLoggerHeartbeat extends BaseCommand
{
    protected $group = 'InnLogger';
    protected $name = 'innlogger:heartbeat';
    protected $description = 'Sends a heartbeat to InnLogger.';

    public function run(array $params)
    {
        $client = service('innlogger');

        if (! $client instanceof InnLoggerClient) {
            CLI::error('service(\'innlogger\') did not return an InnLoggerClient.');

            return EXIT_ERROR;
        }

        $result = $client->heartbeat();

        if ($result->sent()) {
            CLI::write(CLI::color('Heartbeat accepted (HTTP ' . $result->httpStatus . ').', 'green'));

            return EXIT_SUCCESS;
        }

        CLI::error('Heartbeat not sent: ' . ($result->error ?? $result->status));

        return EXIT_ERROR;
    }
}
