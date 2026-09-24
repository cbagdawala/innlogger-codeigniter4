<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Integration;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services as AppServices;
use InnLogger\CodeIgniter4\ClientFactory;
use InnLogger\CodeIgniter4\Config\InnLogger as InnLoggerConfig;
use InnLogger\CodeIgniter4\Config\Services;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;

final class ServicesAndConfigTest extends CIUnitTestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
            }
        }

        $this->savedEnv = [];
        Factories::reset('config');
        AppServices::reset(true);
        parent::tearDown();
    }

    public function testServiceHelperResolvesASharedClient(): void
    {
        $client = service('innlogger');

        $this->assertInstanceOf(InnLoggerClient::class, $client);
        $this->assertSame($client, service('innlogger'));
        $this->assertNotSame($client, single_service('innlogger'));
        $this->assertInstanceOf(InnLoggerClient::class, Services::innlogger(null, false));
    }

    public function testDefaultsWithoutEnvironment(): void
    {
        $settings = ClientFactory::settings(new InnLoggerConfig());

        $this->assertTrue($settings->enabled);
        $this->assertSame(2, $settings->threshold);
        $this->assertSame(2.0, $settings->timeout);
        $this->assertSame('testing', $settings->environment, 'falls back to CodeIgniter ENVIRONMENT');
        $this->assertFalse($settings->isValid());
    }

    public function testReadsTheSpecEnvironmentVariables(): void
    {
        $this->setEnv([
            'INNLOGGER_URL' => 'https://logger.example.com/',
            'INNLOGGER_API_KEY' => 'ilv_abc',
            'INNLOGGER_API_SECRET' => 'ils_def',
            'INNLOGGER_LOG_LEVEL' => '5',
            'INNLOGGER_TIMEOUT' => '1.5',
            'INNLOGGER_ENABLED' => 'false',
            'INNLOGGER_ENVIRONMENT' => 'staging',
            'INNLOGGER_REDACT_FIELDS' => 'ssn, pin',
            'INNLOGGER_RETRIES' => '2',
        ]);

        $config = new InnLoggerConfig();
        $settings = ClientFactory::settings($config);

        $this->assertSame('https://logger.example.com', $settings->url);
        $this->assertSame('ilv_abc', $settings->apiKey);
        $this->assertSame('ils_def', $settings->apiSecret());
        $this->assertSame(5, $settings->threshold);
        $this->assertSame(1.5, $settings->timeout);
        $this->assertFalse($settings->enabled);
        $this->assertSame('staging', $settings->environment);
        $this->assertSame(['ssn', 'pin'], $settings->redactFields);
        $this->assertSame(2, $settings->retries);
        $this->assertTrue($settings->isValid());
    }

    public function testCategoryMaskPatternsAndRetryDefaultsFromEnvironment(): void
    {
        $this->assertSame(1, ClientFactory::settings(new InnLoggerConfig())->retries, 'retries default to 1');
        $this->assertSame('application', ClientFactory::settings(new InnLoggerConfig())->category);

        $this->setEnv([
            'INNLOGGER_CATEGORY' => 'billing',
            'INNLOGGER_MASK_PATTERNS' => '{"/\\\\b\\\\d{3}-\\\\d{2}-\\\\d{4}\\\\b/":"[SSN]"}',
            'INNLOGGER_TIMEOUT' => '300',
        ]);
        $settings = ClientFactory::settings(new InnLoggerConfig());

        $this->assertSame('billing', $settings->category);
        $this->assertSame(['/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]'], $settings->maskPatterns);
        $this->assertSame(30.0, $settings->timeout, 'capped at 30 s');
    }

    public function testConfigDumpsHideTheSecret(): void
    {
        $config = new InnLoggerConfig();
        $config->apiSecret = 'ils_ConfigSecretValue123456';

        $this->assertStringNotContainsString('ils_ConfigSecretValue123456', print_r($config, true));
        ob_start();
        var_dump($config);
        $this->assertStringNotContainsString('ils_ConfigSecretValue123456', (string) ob_get_clean());
    }

    public function testLogLevelAcceptsANameAndZero(): void
    {
        $this->setEnv(['INNLOGGER_LOG_LEVEL' => 'warning']);
        $this->assertSame(3, (new InnLoggerConfig())->logLevel);

        $this->setEnv(['INNLOGGER_LOG_LEVEL' => '0']);
        $this->assertSame(0, ClientFactory::settings(new InnLoggerConfig())->threshold);
    }

    public function testCodeIgniterDotEnvStyleKeysAlsoWork(): void
    {
        $this->setEnv(['innlogger.url' => 'https://ci-style.example.com', 'innlogger.logLevel' => '6']);

        $config = new InnLoggerConfig();

        $this->assertSame('https://ci-style.example.com', $config->url);
        $this->assertSame(6, $config->logLevel);
    }

    public function testServiceUsesTheInjectedConfig(): void
    {
        $config = new InnLoggerConfig();
        $config->url = 'https://injected.example.com';
        $config->apiKey = 'ilv_x';
        $config->apiSecret = 'ils_y';
        $config->logLevel = 7;

        $client = Services::innlogger($config, false);

        $this->assertSame('https://injected.example.com', $client->settings()->url);
        $this->assertSame(7, $client->settings()->threshold);
    }

    public function testConfigRegisteredThroughFactoriesIsUsed(): void
    {
        $config = new InnLoggerConfig();
        $config->url = 'https://factories.example.com';
        Factories::injectMock('config', InnLoggerConfig::class, $config);

        $this->assertSame('https://factories.example.com', single_service('innlogger')->settings()->url);
    }

    public function testServiceCanBeMockedForHostAppTests(): void
    {
        $transport = new FakeTransport();
        $config = new InnLoggerConfig();
        $config->url = 'https://logger.example.com';
        $config->apiKey = 'ilv_x';
        $config->apiSecret = 'ils_y';

        AppServices::injectMock('innlogger', ClientFactory::make($config, $transport));

        service('innlogger')->critical('System failure', ['k' => 'v']);

        $this->assertSame(1, $transport->count());
        $this->assertSame('System failure', $transport->payload()['message']);
    }

    public function testFactoryUsesTheRequestContextProviderUnlessDisabled(): void
    {
        $config = new InnLoggerConfig();
        $config->url = 'https://logger.example.com';
        $config->apiKey = 'ilv_x';
        $config->apiSecret = 'ils_y';
        $config->userIdResolver = static fn (): int => 77;
        $transport = new FakeTransport();

        ClientFactory::make($config, $transport)->error('with context');
        $this->assertSame(77, $transport->payload()['user_id']);

        $config->captureRequestContext = false;
        ClientFactory::make($config, $transport)->error('without context');
        $this->assertArrayNotHasKey('user_id', $transport->payload(1));
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            if (! array_key_exists($name, $this->savedEnv)) {
                $this->savedEnv[$name] = isset($_ENV[$name]) ? (string) $_ENV[$name] : null;
            }

            $_ENV[$name] = $value;
        }
    }
}
