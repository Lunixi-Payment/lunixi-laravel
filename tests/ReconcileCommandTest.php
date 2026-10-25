<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lunixi\Laravel\Events\PaymentReconciled;
use Lunixi\Laravel\Payments\PendingPaymentRepository;
use Lunixi\Sdk\Payment\PaymentStatus;

final class ReconcileCommandTest extends TestCase
{
    public function test_fails_with_guidance_when_repository_is_not_bound(): void
    {
        $this->artisan('lunixi:reconcile')
            ->expectsOutputToContain(PendingPaymentRepository::class)
            ->assertFailed();
    }

    public function test_fires_event_per_payment_and_continues_after_a_failure(): void
    {
        $repository = new class implements PendingPaymentRepository {
            public ?\DateTimeInterface $createdBefore = null;
            public ?int $limit = null;

            public function pendingPaymentIds(\DateTimeInterface $createdBefore, int $limit): iterable
            {
                $this->createdBefore = $createdBefore;
                $this->limit = $limit;

                return ['pay_ok', 'pay_missing', 'pay_unclear'];
            }
        };
        $this->app->instance(PendingPaymentRepository::class, $repository);
        Event::fake([PaymentReconciled::class]);
        Http::fake([
            self::BASE_URL . '/api/v1/auth/token' => Http::response(self::tokenResponse()),
            self::BASE_URL . '/api/v1/payments/pay_ok' => Http::response(['data' => ['id' => 'pay_ok', 'status' => PaymentStatus::CAPTURED]]),
            self::BASE_URL . '/api/v1/payments/pay_missing' => Http::response(['code' => 'PAYMENT_NOT_FOUND', 'message' => 'Not found'], 404),
            self::BASE_URL . '/api/v1/payments/pay_unclear' => Http::response(['data' => ['id' => 'pay_unclear', 'status' => PaymentStatus::NEEDS_RECONCILIATION]]),
        ]);

        $this->artisan('lunixi:reconcile', ['--older-than' => 30, '--limit' => 5])
            ->expectsOutputToContain('pay_missing: HTTP 404')
            ->expectsOutputToContain('Reconciled 2 payment(s), 1 failed.')
            ->assertFailed();

        self::assertSame(5, $repository->limit);
        self::assertLessThanOrEqual(time() - 30 * 60, $repository->createdBefore?->getTimestamp());
        Event::assertDispatchedTimes(PaymentReconciled::class, 2);
        Event::assertDispatched(PaymentReconciled::class, fn (PaymentReconciled $e): bool => $e->paymentId === 'pay_unclear'
            && $e->payment->status() === PaymentStatus::NEEDS_RECONCILIATION);
    }

    public function test_succeeds_when_nothing_is_pending(): void
    {
        $this->app->instance(PendingPaymentRepository::class, new class implements PendingPaymentRepository {
            public function pendingPaymentIds(\DateTimeInterface $createdBefore, int $limit): iterable
            {
                return [];
            }
        });

        $this->artisan('lunixi:reconcile')
            ->expectsOutputToContain('Reconciled 0 payment(s), 0 failed.')
            ->assertSuccessful();
    }
}
