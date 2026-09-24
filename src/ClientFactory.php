<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4;

use InnLogger\CodeIgniter4\Config\InnLogger as InnLoggerConfig;
use InnLogger\CodeIgniter4\Context\RequestContextProvider;
use InnLogger\CodeIgniter4\Core\ContextProviderInterface;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\NullContextProvider;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Transport\TransportInterface;

/**
 * Maps CodeIgniter's Config\InnLogger onto the framework-agnostic client.
 */
final class ClientFactory
{
    private function __construct()
    {
    }

    public static function settings(InnLoggerConfig $config): Settings
    {
        $environment = $config->environment !== ''
            ? $config->environment
            : (defined('ENVIRONMENT') ? (string) constant('ENVIRONMENT') : 'production');

        return Settings::fromArray([
            'enabled' => $config->enabled,
            'url' => $config->url,
            'api_key' => $config->apiKey,
            'api_secret' => $config->apiSecret,
            'log_level' => $config->logLevel,
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'environment' => $environment,
            'application' => $config->application,
            'app_version' => $config->appVersion,
            'retries' => $config->retries,
            'allow_insecure' => $config->allowInsecure,
            'debug' => $config->debug,
            'redact_fields' => $config->redactFields,
            'mask_patterns' => $config->maskPatterns,
            'category' => $config->category,
        ]);
    }

    public static function make(
        InnLoggerConfig $config,
        ?TransportInterface $transport = null,
        ?ContextProviderInterface $contextProvider = null,
    ): InnLoggerClient {
        $contextProvider ??= $config->captureRequestContext
            ? new RequestContextProvider(userIdResolver: $config->userIdResolver)
            : new NullContextProvider();

        return new InnLoggerClient(self::settings($config), $transport, $contextProvider);
    }
}
