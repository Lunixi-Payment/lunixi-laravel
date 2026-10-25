<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Http\HttpResponse;

/**
 * SDK transport over the Laravel HTTP client, so the application's Http::fake()
 * and HTTP client events cover Lunixi calls.
 *
 * Follows the HttpClientInterface contract exactly: every completed exchange,
 * 4xx/5xx included, comes back as an HttpResponse; only a transport failure
 * throws ApiException (statusCode 0). The body is sent byte for byte as the SDK
 * signed it, and no Laravel retry is added (the SDK retries).
 */
final class LaravelHttpClient implements HttpClientInterface
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $request = $this->http->withHeaders($headers)
            ->timeout($timeout)
            ->withoutRedirecting();

        if ($body !== null) {
            $request = $request->withBody($body, self::contentType($headers));
        }

        try {
            $response = $request->send(strtoupper($method), $url);
        } catch (ConnectionException $e) {
            throw new ApiException('HTTP transport error: ' . $e->getMessage(), 0, null, null, null, $e);
        }

        $responseHeaders = [];
        foreach ($response->headers() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', (array) $values);
        }

        return new HttpResponse($response->status(), $responseHeaders, $response->body());
    }

    /** @param array<string,string> $headers */
    private static function contentType(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                return $value;
            }
        }

        return 'application/json';
    }
}
