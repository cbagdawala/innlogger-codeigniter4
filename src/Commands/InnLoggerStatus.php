<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;

/**
 * php spark innlogger:status
 *
 * Prints the effective configuration (the API secret is never printed) without sending anything.
 */
class InnLoggerStatus extends BaseCommand
{
    protected $group = 'InnLogger';
    protected $name = 'innlogger:status';
    protected $description = 'Shows the effective InnLogger configuration (secret hidden).';

    public function run(array $params)
    {
        $client = service('innlogger');

        if (! $client instanceof InnLoggerClient) {
            CLI::error('service(\'innlogger\') did not return an InnLoggerClient.');

            return EXIT_ERROR;
        }

        $settings = $client->settings();
        $rows = [];

        foreach ($settings->describe() as $key => $value) {
            $rows[] = [$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        }

        CLI::table($rows, ['Setting', 'Value']);

        $invalid = $settings->invalidReason();
        CLI::write('Configuration: ' . ($invalid === null ? CLI::color('valid', 'green') : CLI::color('invalid - ' . $invalid, 'red')));

        return $invalid === null ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
