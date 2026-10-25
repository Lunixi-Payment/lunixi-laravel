<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Payments;

/**
 * Implemented by your application so lunixi:reconcile can find payments whose
 * final result has not arrived yet (for example a lost webhook). Bind it in a
 * service provider:
 *
 *   $this->app->bind(PendingPaymentRepository::class, OrderPendingPayments::class);
 */
interface PendingPaymentRepository
{
    /**
     * Lunixi payment ids (CheckoutIntent::paymentId()) still waiting for a
     * final result and created before $createdBefore, oldest first.
     *
     * @return iterable<string>
     */
    public function pendingPaymentIds(\DateTimeInterface $createdBefore, int $limit): iterable;
}
