<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Lunixi\Laravel\Events\PaymentReconciled;
use Lunixi\Laravel\LunixiManager;
use Lunixi\Laravel\Payments\PendingPaymentRepository;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\LunixiException;

/**
 * Reads the current state of payments still pending in your application and
 * fires PaymentReconciled for each, so an order does not stay pending when a
 * webhook was lost. Schedule it:
 *
 *   Schedule::command('lunixi:reconcile')->everyFiveMinutes()->withoutOverlapping();
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'lunixi:reconcile
        {--older-than= : Only payments created at least this many minutes ago}
        {--limit= : Maximum number of payments to read}
        {--connection= : Lunixi connection name}';

    protected $description = 'Read the status of pending Lunixi payments and fire PaymentReconciled';

    public function handle(LunixiManager $lunixi, EventDispatcher $events): int
    {
        if (!$this->laravel->bound(PendingPaymentRepository::class)) {
            $this->error('Bind ' . PendingPaymentRepository::class . ' in a service provider to use lunixi:reconcile.');

            return self::FAILURE;
        }

        /** @var PendingPaymentRepository $repository */
        $repository = $this->laravel->make(PendingPaymentRepository::class);
        $olderThan = $this->intOption('older-than', 'lunixi.reconcile.older_than_minutes', 15);
        $limit = $this->intOption('limit', 'lunixi.reconcile.limit', 100);
        $createdBefore = new \DateTimeImmutable("-{$olderThan} minutes");

        $connection = $this->option('connection');
        $payments = $lunixi->connection(is_string($connection) && $connection !== '' ? $connection : null)->payments();

        $read = 0;
        $failed = 0;
        foreach ($repository->pendingPaymentIds($createdBefore, $limit) as $paymentId) {
            try {
                $payment = $payments->get($paymentId);
            } catch (LunixiException $e) {
                $failed++;
                $this->warn("{$paymentId}: " . self::describe($e));

                continue;
            }

            $events->dispatch(new PaymentReconciled($paymentId, $payment));
            $read++;
            $this->line("{$paymentId}: {$payment->status()}", null, 'v');
        }

        $this->info("Reconciled {$read} payment(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function intOption(string $option, string $configKey, int $default): int
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            $value = $this->laravel->make('config')->get($configKey, $default);
        }

        return max(1, (int) $value);
    }

    private static function describe(LunixiException $e): string
    {
        if ($e instanceof ApiException) {
            $parts = array_filter([
                $e->getStatusCode() > 0 ? 'HTTP ' . $e->getStatusCode() : 'network error',
                $e->getErrorCode(),
                $e->getRequestId() !== null ? 'request ' . $e->getRequestId() : null,
            ]);

            return implode(', ', $parts);
        }

        return $e->getMessage();
    }
}
