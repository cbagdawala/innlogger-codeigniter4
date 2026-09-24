<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;

final class CommandsTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    private function useClient(array $overrides = []): void
    {
        Services::injectMock('innlogger', new InnLoggerClient(
            Settings::fromArray($overrides + ['url' => 'https://logger.example.com', 'api_key' => 'ilv_key12345', 'api_secret' => 'ils_topsecret', 'log_level' => 0]),
            $this->transport,
        ));
    }

    private function cliOutput(): string
    {
        return preg_replace('/\e\[[0-9;]*m/', '', $this->getStreamFilterBuffer()) ?? '';
    }

    public function testTestCommandSendsAnEventAndReportsIt(): void
    {
        $this->useClient();

        command('innlogger:test');
        $out = $this->cliOutput();

        $this->assertSame(1, $this->transport->count(), 'sent despite threshold 0');
        $eventId = $this->transport->payload()['event_id'];
        $this->assertStringContainsString('Configuration: valid', $out);
        $this->assertStringContainsString('Event ID:      ' . $eventId, $out);
        $this->assertStringContainsString('Authenticated: yes', $out);
        $this->assertStringContainsString('HTTP status:   202', $out);
        $this->assertStringContainsString('Accepted.', $out);
        $this->assertStringNotContainsString('ils_topsecret', $out);
    }

    public function testTestCommandReportsBadCredentials(): void
    {
        $this->useClient();
        $this->transport->push(new Response(401, '{"success":false,"message":"Invalid credentials"}'));

        command('innlogger:test');
        $out = $this->cliOutput();

        $this->assertStringContainsString('Authenticated: no', $out);
        $this->assertStringContainsString('HTTP 401 (Invalid credentials)', $out);
    }

    public function testTestCommandStopsOnInvalidConfiguration(): void
    {
        $this->useClient(['url' => 'http://insecure.example.com']);

        command('innlogger:test');

        $this->assertStringContainsString('must use https', $this->cliOutput());
        $this->assertSame(0, $this->transport->count());
    }

    public function testStatusCommandHidesTheSecret(): void
    {
        $this->useClient();

        command('innlogger:status');
        $out = $this->cliOutput();

        $this->assertStringContainsString('https://logger.example.com', $out);
        $this->assertStringContainsString('(set)', $out);
        $this->assertStringNotContainsString('ils_topsecret', $out);
        $this->assertSame(0, $this->transport->count());
    }

    public function testHeartbeatCommand(): void
    {
        $this->useClient();
        $this->transport->push(new Response(204));

        command('innlogger:heartbeat');

        $this->assertStringContainsString('Heartbeat accepted (HTTP 204)', $this->cliOutput());
        $this->assertStringEndsWith('/api/v1/heartbeat', $this->transport->last()['url']);
    }
}
