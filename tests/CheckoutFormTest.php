<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Lunixi\Sdk\Payment\CheckoutIntent;

final class CheckoutFormTest extends TestCase
{
    private const SCRIPT = '<script id="lunixiTokenScript">(function(){var c={"token":"t_1","a":"<b>"};})();</script>';

    public function test_renders_mount_point_and_script_verbatim(): void
    {
        $html = Blade::render('<x-lunixi::checkout-form :script="$script" />', ['script' => self::SCRIPT]);

        self::assertStringContainsString('<div id="lunixi-checkout-form" class="lunixi-checkout-form"></div>', $html);
        self::assertStringContainsString(self::SCRIPT, $html);
    }

    public function test_renders_from_checkout_intent(): void
    {
        $intent = new CheckoutIntent(['status' => 'success', 'data' => [
            'paymentId' => 'pay_1',
            'token' => 't_1',
            'checkoutFormContent' => self::SCRIPT,
        ]]);

        $html = Blade::render('<x-lunixi::checkout-form :intent="$intent" class="my-form" />', ['intent' => $intent]);

        self::assertStringContainsString('class="lunixi-checkout-form my-form"', $html);
        self::assertStringContainsString(self::SCRIPT, $html);
    }

    public function test_adds_nonce_to_first_script_tag(): void
    {
        $html = Blade::render('<x-lunixi::checkout-form :script="$script" nonce="n0&nce" />', ['script' => self::SCRIPT]);

        self::assertStringContainsString('<script nonce="n0&amp;nce" id="lunixiTokenScript">', $html);
        self::assertSame(1, substr_count($html, 'nonce='));
    }

    public function test_keeps_existing_nonce(): void
    {
        $script = '<script nonce="gateway">x()</script>';

        $html = Blade::render('<x-lunixi::checkout-form :script="$script" nonce="mine" />', ['script' => $script]);

        self::assertStringContainsString($script, $html);
        self::assertStringNotContainsString('mine', $html);
    }

    public function test_empty_script_shows_message_without_mount_point(): void
    {
        $html = Blade::render('<x-lunixi::checkout-form script="" />');

        self::assertStringNotContainsString('lunixi-checkout-form"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString(e(__('lunixi::checkout.unavailable')), $html);
    }

    public function test_message_is_translated(): void
    {
        $this->app->setLocale('tr');

        $html = Blade::render('<x-lunixi::checkout-form script="" />');

        self::assertStringContainsString('Ödeme başlatılamadı', $html);
    }
}
