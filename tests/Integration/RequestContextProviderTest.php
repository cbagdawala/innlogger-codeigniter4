<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Integration;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use InnLogger\CodeIgniter4\Context\RequestContextProvider;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;
use RuntimeException;

final class RequestContextProviderTest extends CIUnitTestCase
{
    private function request(string $path, string $method = 'POST', array $headers = []): IncomingRequest
    {
        $app = config(App::class);
        $request = new IncomingRequest($app, new SiteURI($app, $path), null, new UserAgent());
        $request = $request->withMethod($method);

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    public function testCapturesUriMethodRouteRequestIdAndUser(): void
    {
        $request = $this->request('api/payment?amount=5&token=abc', 'post', ['X-Request-Id' => 'req_123']);
        $provider = new RequestContextProvider(
            requestResolver: static fn () => $request,
            userIdResolver: static fn () => 123,
            routeResolver: static fn () => 'api/payment',
        );

        $this->assertSame([
            'url' => '/api/payment?amount=5&token=abc',
            'http_method' => 'POST',
            'request_id' => 'req_123',
            'user_id' => 123,
            'route' => 'api/payment',
        ], $provider->context());
    }

    public function testGeneratesAStableRequestIdPerRequest(): void
    {
        $request = $this->request('home', 'GET');
        $provider = new RequestContextProvider(requestResolver: static fn () => $request, routeResolver: static fn () => null);

        $first = $provider->context()['request_id'];

        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', $first);
        $this->assertSame($first, $provider->context()['request_id']);

        $other = $this->request('home', 'GET');
        $provider2 = new RequestContextProvider(requestResolver: static fn () => $other, routeResolver: static fn () => null);
        $this->assertNotSame($first, $provider2->context()['request_id']);
    }

    public function testRejectsUnsafeRequestIdHeaders(): void
    {
        $request = $this->request('home', 'GET', ['X-Request-Id' => 'bad id;<x>']);
        $provider = new RequestContextProvider(requestResolver: static fn () => $request, routeResolver: static fn () => null);

        $this->assertMatchesRegularExpression('/^req_/', $provider->context()['request_id']);
    }

    public function testCliRequestOnlyCarriesTheUser(): void
    {
        // In PHPUnit service('request') is not an IncomingRequest for a web route.
        $provider = new RequestContextProvider(requestResolver: static fn () => null, userIdResolver: static fn () => 'u-9');

        $this->assertSame(['user_id' => 'u-9'], $provider->context());
    }

    public function testResolverFailuresAreSwallowed(): void
    {
        $request = $this->request('x', 'GET');
        $provider = new RequestContextProvider(
            requestResolver: static fn () => $request,
            userIdResolver: static fn () => throw new RuntimeException('no session'),
            routeResolver: static fn () => throw new RuntimeException('no router'),
        );

        $context = $provider->context();

        $this->assertSame('/x', $context['url']);
        $this->assertArrayNotHasKey('user_id', $context);
        $this->assertArrayNotHasKey('route', $context);
    }

    public function testRequestFieldsReachThePayloadWithSensitiveQueryRedacted(): void
    {
        $request = $this->request('api/login?password=hunter2', 'POST');
        $transport = new FakeTransport();
        $client = new InnLoggerClient(
            Settings::fromArray(['url' => 'https://logger.example.com', 'api_key' => 'k', 'api_secret' => 's', 'log_level' => 2]),
            $transport,
            new RequestContextProvider(requestResolver: static fn () => $request, userIdResolver: static fn () => 5, routeResolver: static fn () => 'api/login'),
        );

        $client->error('Login failed');
        $payload = $transport->payload();

        $this->assertSame('/api/login?password=%5BREDACTED%5D', $payload['url']);
        $this->assertSame('POST', $payload['http_method']);
        $this->assertSame(5, $payload['user_id']);
        $this->assertSame('api/login', $payload['metadata']['route']);
        $this->assertStringNotContainsString('hunter2', $transport->last()['body']);
    }
}
