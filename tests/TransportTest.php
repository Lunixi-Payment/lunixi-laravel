<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Lunixi\Laravel\Auth\CacheTokenStore;
use Lunixi\Laravel\Facades\Lunixi;
use Lunixi\Laravel\Http\LaravelHttpClient;
use Lunixi\Sdk\Exception\ApiException;

final class TransportTest extends TestCase
{
    public function test_body_is_sent_byte_for_byte(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);
        $raw = '{"b":1,"a":"ş ü","url":"https:\/\/x"}';

        (new LaravelHttpClient($this->app->make(\Illuminate\Http\Client\Factory::class)))
            ->send('POST', 'https://example.test/x', ['Content-Type' => 'application/json', 'X-Sig' => 'abc'], $raw, 3.0);

        Http::assertSent(fn (Request $request): bool => $request->body() === $raw
            && $request->hasHeader('X-Sig', 'abc')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->method() === 'POST');
    }

    public function test_error_status_returns_response_not_exception(): void
    {
        Http::fake(['*' => Http::response('{"code":"BOOM"}', 503, ['X-Request-Id' => 'req_1'])]);

        $response = (new LaravelHttpClient($this->app->make(\Illuminate\Http\Client\Factory::class)))
            ->send('GET', 'https://example.test/x', [], null, 3.0);

        self::assertSame(503, $response->statusCode());
        self::assertSame('{"code":"BOOM"}', $response->body());
        self::assertSame('req_1', $response->header('x-request-id'));
    }

    public function test_connection_failure_becomes_api_exception_with_status_zero(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        try {
            (new LaravelHttpClient($this->app->make(\Illuminate\Http\Client\Factory::class)))
                ->send('GET', 'https://example.test/x', [], null, 3.0);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(0, $e->getStatusCode());
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function test_sdk_call_runs_through_http_fake_and_caches_token(): void
    {
        Http::fake([
            self::BASE_URL . '/api/v1/auth/token' => Http::response(self::tokenResponse('tok_1')),
            self::BASE_URL . '/api/v1/payments/*' => Http::response(['status' => 'success', 'data' => ['id' => 'pay_1', 'status' => 'CAPTURED']]),
        ]);

        self::assertSame('CAPTURED', Lunixi::payments()->get('pay_1')->status());
        self::assertSame('CAPTURED', Lunixi::payments()->get('pay_1')->status());

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/payments/pay_1')
            && $request->hasHeader('Authorization', 'Bearer tok_1'));
    }

    public function test_token_is_stored_encrypted(): void
    {
        $store = new CacheTokenStore(Cache::store('array'), $this->app->make('encrypter'));
        $store->set('k', 'tok_secret', 60);

        self::assertNotSame('tok_secret', Cache::store('array')->get('k'));
        self::assertSame('tok_secret', Crypt::decryptString(Cache::store('array')->get('k')));
        self::assertSame('tok_secret', $store->get('k'));
    }

    public function test_undecryptable_token_reads_as_absent(): void
    {
        Cache::store('array')->put('k', 'not-encrypted', 60);
        $store = new CacheTokenStore(Cache::store('array'), $this->app->make('encrypter'));

        self::assertNull($store->get('k'));
    }

    public function test_non_positive_ttl_is_not_stored(): void
    {
        $store = new CacheTokenStore(Cache::store('array'));
        $store->set('k', 'tok', 0);

        self::assertNull($store->get('k'));
    }

    public function test_plain_store_when_encryption_is_off(): void
    {
        $store = new CacheTokenStore(Cache::store('array'));
        $store->set('k', 'tok', 60);
        $store->delete('k');

        self::assertNull($store->get('k'));
    }
}
