<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Lunixi\Laravel\Facades\Lunixi;
use Lunixi\Sdk\Payment\CheckoutIntent;
use Lunixi\Sdk\Payment\CreateIntentRequest;

final class CheckoutPresentationCompatibilityTest extends TestCase
{
    public function test_same_response_supports_existing_embedded_component_and_optional_validated_redirect(): void
    {
        $script = '<script>window.existingCheckoutBootstrap=true;</script>';
        $url = 'https://pay.lunixi.com/payment/hosted-token-independent-001';
        $this->fakeIntent(['paymentPageUrl' => $url, 'checkoutFormContent' => $script]);

        $intent = $this->createIntent();
        $html = Blade::render('<x-lunixi::checkout-form :intent="$intent" />', ['intent' => $intent]);

        self::assertSame('embedded-token-original-001', $intent->token());
        self::assertStringContainsString($script, $html);
        self::assertStringNotContainsString($url, $html);
        self::assertSame($url, $intent->paymentPageUrl());

        $redirect = redirect()->away($intent->paymentPageUrl(), 303, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame($url, $redirect->getTargetUrl());
        self::assertTrue($redirect->headers->hasCacheControlDirective('no-store'));
        self::assertSame('no-referrer', $redirect->headers->get('Referrer-Policy'));
        Http::assertSentCount(2); // one authentication and one intent, no second checkout
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/payments/intents')
            && $request->hasHeader('Idempotency-Key', 'merchant-order-123-attempt-1'));
    }

    public function test_missing_hosted_url_does_not_invent_token_url_or_break_existing_embedded_script(): void
    {
        $script = '<script>window.existingCheckoutBootstrap=true;</script>';
        $this->fakeIntent(['checkoutFormContent' => $script]);

        $intent = $this->createIntent();

        self::assertSame('', $intent->paymentPageUrl());
        self::assertStringContainsString($script, Blade::render(
            '<x-lunixi::checkout-form :intent="$intent" />',
            ['intent' => $intent]
        ));
        Http::assertSentCount(2);
    }

    public function test_unsafe_backend_url_is_rejected_after_real_facade_transport(): void
    {
        $this->fakeIntent(['paymentPageUrl' => 'https://pay.lunixi.com.attacker.test/payment/hosted-token-001']);
        $intent = $this->createIntent();

        Http::assertSentCount(2);
        $this->expectException(\UnexpectedValueException::class);
        $intent->paymentPageUrl();
    }

    /** @param array<string, string> $extra */
    private function fakeIntent(array $extra): void
    {
        Http::fake([
            self::BASE_URL . '/api/v1/auth/token' => Http::response(self::tokenResponse()),
            self::BASE_URL . '/api/v1/payments/intents' => Http::response([
                'status' => 'success',
                'data' => array_merge([
                    'paymentId' => 'payment-123',
                    'token' => 'embedded-token-original-001',
                ], $extra),
            ]),
        ]);
    }

    private function createIntent(): CheckoutIntent
    {
        return Lunixi::payments()->createIntent(
            new CreateIntentRequest(1250, 'TRY', 'order-123'),
            'merchant-order-123-attempt-1'
        );
    }
}
