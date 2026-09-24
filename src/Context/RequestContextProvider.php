<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Context;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use InnLogger\CodeIgniter4\Core\ContextProviderInterface;
use Throwable;

/**
 * Captures request context from CodeIgniter 4: URI (path + query, sensitive
 * query parameters are redacted later), HTTP method, matched route, request ID
 * and the authenticated user ID. Headers, cookies and bodies are never captured.
 */
class RequestContextProvider implements ContextProviderInterface
{
    private const REQUEST_ID_HEADERS = ['X-Request-Id', 'X-Correlation-Id', 'Request-Id'];

    private ?int $requestObjectId = null;
    private ?string $requestId = null;

    /**
     * @param (Closure(): (IncomingRequest|object|null))|null $requestResolver defaults to service('request')
     * @param (Closure(): (int|string|null))|null             $userIdResolver  defaults to Shield's auth()->id() when available
     * @param (Closure(): (string|null))|null                 $routeResolver   defaults to the router's matched route
     */
    public function __construct(
        private readonly ?Closure $requestResolver = null,
        private readonly ?Closure $userIdResolver = null,
        private readonly ?Closure $routeResolver = null,
    ) {
    }

    public function context(): array
    {
        $request = $this->request();

        if (! $request instanceof IncomingRequest) {
            return array_filter(['user_id' => $this->userId()], static fn ($v): bool => $v !== null);
        }

        $context = [
            'url' => $this->url($request),
            'http_method' => $this->safe(static fn () => strtoupper((string) $request->getMethod())),
            'request_id' => $this->requestId($request),
            'user_id' => $this->userId(),
            'route' => $this->route(),
        ];

        return array_filter($context, static fn ($v): bool => $v !== null && $v !== '');
    }

    private function request(): ?object
    {
        return $this->safe(function () {
            if ($this->requestResolver !== null) {
                return ($this->requestResolver)();
            }

            return function_exists('service') ? service('request') : null;
        });
    }

    private function url(IncomingRequest $request): ?string
    {
        return $this->safe(static function () use ($request): string {
            $uri = $request->getUri();
            // SiteURI (4.4+) knows the route path without baseURL/index.php.
            $path = '/' . ltrim(method_exists($uri, 'getRoutePath') ? $uri->getRoutePath() : $uri->getPath(), '/');
            $query = $uri->getQuery();

            return $query !== '' ? $path . '?' . $query : $path;
        });
    }

    private function requestId(IncomingRequest $request): string
    {
        $objectId = spl_object_id($request);

        if ($this->requestObjectId === $objectId && $this->requestId !== null) {
            return $this->requestId;
        }

        $id = null;

        foreach (self::REQUEST_ID_HEADERS as $header) {
            $value = $this->safe(static fn () => $request->getHeaderLine($header));

            if (is_string($value) && preg_match('/^[A-Za-z0-9._:\-]{1,128}$/', $value) === 1) {
                $id = $value;
                break;
            }
        }

        $this->requestObjectId = $objectId;
        $this->requestId = $id ?? 'req_' . bin2hex(random_bytes(8));

        return $this->requestId;
    }

    private function userId(): int|string|null
    {
        $id = $this->safe(function () {
            if ($this->userIdResolver !== null) {
                return ($this->userIdResolver)();
            }

            // CodeIgniter Shield, when installed and its helper is loaded.
            if (function_exists('auth') && class_exists('CodeIgniter\Shield\Auth', false)) {
                return auth()->id();
            }

            return null;
        });

        return is_int($id) || (is_string($id) && $id !== '') ? $id : null;
    }

    private function route(): ?string
    {
        $route = $this->safe(function () {
            if ($this->routeResolver !== null) {
                return ($this->routeResolver)();
            }

            if (! function_exists('service')) {
                return null;
            }

            $router = service('router');
            $matched = method_exists($router, 'getMatchedRoute') ? $router->getMatchedRoute() : null;

            if (is_array($matched) && isset($matched[0]) && is_string($matched[0])) {
                return $matched[0] === '' ? '/' : $matched[0];
            }

            $controller = $router->controllerName();

            return is_string($controller) && $controller !== '' ? ltrim($controller, '\\') . '::' . $router->methodName() : null;
        });

        return is_string($route) ? $route : null;
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T|null
     */
    private function safe(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
