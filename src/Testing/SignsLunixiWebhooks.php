<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Testing;

use Illuminate\Testing\TestResponse;
use Lunixi\Sdk\Webhook\WebhookVerifier;

/**
 * For your feature tests: posts a webhook to the Lunixi route signed the way
 * Lunixi signs it (v2 HMAC over "<eventId>.<timestamp>.<rawBody>"), so your
 * listeners run through real signature verification.
 *
 *   $this->postLunixiWebhook('payment.captured', ['paymentId' => 'pay_1'])->assertOk();
 *
 * Use it in a class extending Illuminate\Foundation\Testing\TestCase.
 */
trait SignsLunixiWebhooks
{
    /**
     * @param array<string,mixed> $data    The event payload (envelope `data`).
     * @param string|null         $eventId Reuse an id to test redelivery.
     * @param string|null         $secret  Defaults to `lunixi.webhooks.secret`.
     */
    protected function postLunixiWebhook(
        string $type,
        array $data = [],
        ?string $eventId = null,
        ?string $secret = null,
        string $uri = ''
    ): TestResponse {
        $eventId ??= 'evt_test_' . bin2hex(random_bytes(12));
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $body = (string) json_encode(
            ['id' => $eventId, 'type' => $type, 'environment' => 'TEST', 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $secret ??= (string) config('lunixi.webhooks.secret');

        $headers = [
            'Content-Type' => 'application/json',
            WebhookVerifier::HEADER_EVENT_ID => $eventId,
            WebhookVerifier::HEADER_EVENT_TYPE => $type,
            WebhookVerifier::HEADER_TIMESTAMP => $timestamp,
            WebhookVerifier::HEADER_SIGNATURE => 'v2=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $body, $secret),
        ];

        return $this->call(
            'POST',
            $uri !== '' ? $uri : route('lunixi.webhook'),
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $body
        );
    }
}
