<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Support;

use InnLogger\CodeIgniter4\Core\ContextProviderInterface;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Settings;

trait ClientFactoryTrait
{
    protected FakeTransport $transport;

    /** @var list<string> */
    protected array $diagnostics = [];

    protected function client(array $overrides = [], ?ContextProviderInterface $context = null): InnLoggerClient
    {
        $this->transport ??= new FakeTransport();

        $settings = Settings::fromArray($overrides + [
            'enabled' => true,
            'url' => TestCredentials::URL,
            'api_key' => TestCredentials::KEY,
            'api_secret' => TestCredentials::SECRET,
            'log_level' => 7,
            'environment' => 'production',
            'application' => 'trusted-nanny',
            'app_version' => '1.4.2',
            'hostname' => 'server01',
            'retry_delay_ms' => 0,
            'retries' => 0,
        ]);

        return new InnLoggerClient($settings, $this->transport, $context, function (string $line): void {
            $this->diagnostics[] = $line;
        });
    }
}
