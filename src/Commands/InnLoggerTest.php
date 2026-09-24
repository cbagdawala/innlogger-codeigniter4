<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\SendResult;

/**
 * php spark innlogger:test
 *
 * Sends one INFO test event, ignoring the threshold and the enabled flag, and
 * reports configuration validity, reachability, authentication, the HTTP status
 * and the event ID. Exit code 0 only when the portal accepted the event.
 */
class InnLoggerTest extends BaseCommand
{
    protected $group = 'InnLogger';
    protected $name = 'innlogger:test';
    protected $description = 'Sends a test event to InnLogger and reports the result.';
    protected $usage = 'innlogger:test [message]';
    protected $arguments = ['message' => 'Optional test message.'];

    public function run(array $params)
    {
        $client = service('innlogger');

        if (! $client instanceof InnLoggerClient) {
            CLI::error('service(\'innlogger\') did not return an InnLoggerClient.');

            return EXIT_ERROR;
        }

        $settings = $client->settings();
        $invalid = $settings->invalidReason();

        CLI::write('Configuration: ' . ($invalid === null ? CLI::color('valid', 'green') : CLI::color('invalid - ' . $invalid, 'red')));

        if ($invalid !== null) {
            return EXIT_ERROR;
        }

        if (! $settings->enabled) {
            CLI::write(CLI::color('Note: INNLOGGER_ENABLED is false; normal events are not sent. Sending the test anyway.', 'yellow'));
        }

        $message = trim(implode(' ', $params));
        $result = $client->sendTestEvent($message !== '' ? $message : 'InnLogger test event from ' . (gethostname() ?: 'CodeIgniter 4'));

        CLI::write('Endpoint:      ' . $settings->url . InnLoggerClient::LOGS_PATH);
        CLI::write('Event ID:      ' . ($result->eventId ?? '-'));
        CLI::write('Reachable:     ' . ($result->httpStatus !== null ? CLI::color('yes', 'green') : CLI::color('no', 'red')));
        CLI::write('Authenticated: ' . $this->authLabel($result));
        CLI::write('HTTP status:   ' . ($result->httpStatus ?? '-'));

        if ($result->logId !== null) {
            CLI::write('Log ID:        ' . $result->logId);
        }

        if ($result->sent()) {
            CLI::write(CLI::color($result->duplicate() ? 'Accepted (duplicate event).' : 'Accepted.', 'green'));

            return EXIT_SUCCESS;
        }

        CLI::error('Not accepted: ' . ($result->error ?? $result->status));

        return EXIT_ERROR;
    }

    private function authLabel(SendResult $result): string
    {
        return match (true) {
            $result->httpStatus === null => '-',
            $result->httpStatus === 401 => CLI::color('no (invalid credentials, clock skew or replayed nonce)', 'red'),
            $result->httpStatus === 403 => CLI::color('no (project disabled)', 'red'),
            default => CLI::color('yes', 'green'),
        };
    }
}
