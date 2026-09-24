<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Exceptions;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions as ExceptionsConfig;
use InnLogger\CodeIgniter4\Config\InnLogger as InnLoggerConfig;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Severity;
use Throwable;

/**
 * Reports uncaught exceptions to InnLogger, then hands them to CodeIgniter's
 * normal handler (or the one you pass), so the error response is unchanged.
 *
 * app/Config/Exceptions.php (CodeIgniter 4.4+):
 *
 *   public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
 *   {
 *       return new \InnLogger\CodeIgniter4\Exceptions\ReportingExceptionHandler($this);
 *   }
 *
 * Status codes in Config\InnLogger::$ignoreStatusCodes (default [404]) are not reported.
 * Status >= 500 is reported as CRITICAL, anything else as ERROR.
 */
class ReportingExceptionHandler implements ExceptionHandlerInterface
{
    private readonly ExceptionHandlerInterface $inner;

    public function __construct(
        ExceptionsConfig $config,
        ?ExceptionHandlerInterface $inner = null,
        private ?InnLoggerClient $client = null,
        private ?InnLoggerConfig $innLoggerConfig = null,
    ) {
        $this->inner = $inner ?? new ExceptionHandler($config);
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $this->report($exception, $statusCode);

        $this->inner->handle($exception, $request, $response, $statusCode, $exitCode);
    }

    /**
     * Report without handling. Never throws.
     */
    public function report(Throwable $exception, int $statusCode = 500): void
    {
        try {
            $config = $this->innLoggerConfig ??= config(InnLoggerConfig::class);

            if (! $config->autoException || in_array($statusCode, $config->ignoreStatusCodes, true)) {
                return;
            }

            $client = $this->client ??= service('innlogger');

            $client->exception(
                $exception,
                ['category' => 'exception'],
                $statusCode >= 500 ? Severity::CRITICAL : Severity::ERROR,
                ['http_status' => $statusCode],
            );
        } catch (Throwable) {
            // InnLogger must never stop CodeIgniter from handling the original exception.
        }
    }
}
