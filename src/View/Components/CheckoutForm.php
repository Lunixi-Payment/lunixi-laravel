<?php

declare(strict_types=1);

namespace Lunixi\Laravel\View\Components;

use Illuminate\View\Component;
use Lunixi\Sdk\Payment\CheckoutIntent;

/**
 * Renders the Lunixi checkout form of a payment intent:
 *
 *   <x-lunixi::checkout-form :intent="$intent" />
 *   <x-lunixi::checkout-form :script="$order->lunixi_checkout_script" :nonce="Vite::cspNonce()" />
 *
 * `checkoutFormContent()` is a self-contained <script> made by the Lunixi
 * gateway (one-time token and form settings inside). It loads the Lunixi
 * checkout runtime and mounts the form into #lunixi-checkout-form. It is
 * rendered as is; only a CSP nonce is added when one is given.
 */
final class CheckoutForm extends Component
{
    public const MOUNT_ID = 'lunixi-checkout-form';

    public readonly string $bootstrapScript;

    public readonly string $mountId;

    public function __construct(
        ?CheckoutIntent $intent = null,
        ?string $script = null,
        ?string $nonce = null
    ) {
        $content = $intent !== null ? $intent->checkoutFormContent() : (string) $script;
        $this->bootstrapScript = self::withNonce(trim($content), $nonce);
        $this->mountId = self::MOUNT_ID;
    }

    public function render(): string
    {
        return 'lunixi::components.checkout-form';
    }

    /** Adds the nonce to the first <script> tag unless it already has one. */
    private static function withNonce(string $script, ?string $nonce): string
    {
        if ($script === '' || $nonce === null || $nonce === '') {
            return $script;
        }

        $attribute = 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

        return (string) preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script ' . $attribute, $script, 1);
    }
}
