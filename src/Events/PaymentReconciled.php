<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Events;

use Lunixi\Sdk\Payment\PaymentIntent;

/**
 * lunixi:reconcile read the current state of a pending payment from Lunixi.
 *
 * The status may still be in progress or need reconciliation
 * (PaymentStatus::NEEDS_RECONCILIATION). Mark the order paid only for a
 * captured or authorized status; treat anything unclear as not paid.
 */
final class PaymentReconciled
{
    public function __construct(
        public readonly string $paymentId,
        public readonly PaymentIntent $payment
    ) {
    }
}
