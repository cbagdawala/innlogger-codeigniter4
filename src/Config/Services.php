<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Config;

use CodeIgniter\Config\BaseService;
use InnLogger\CodeIgniter4\ClientFactory;
use InnLogger\CodeIgniter4\Config\InnLogger as InnLoggerConfig;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;

/**
 * Registers service('innlogger'). CodeIgniter discovers this file through
 * Composer package auto-discovery (Config\Modules::$discoverInComposer, on by default).
 */
class Services extends BaseService
{
    public static function innlogger(?InnLoggerConfig $config = null, bool $getShared = true): InnLoggerClient
    {
        if ($getShared) {
            return static::getSharedInstance('innlogger', $config);
        }

        // config() prefers an app/Config/InnLogger.php (extending this package's class) when one exists.
        $config ??= config(InnLoggerConfig::class);

        return ClientFactory::make($config);
    }
}
