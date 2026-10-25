<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Webhooks;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Lunixi\Laravel\Events\WebhookReceived;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * Hands a verified webhook to the application exactly once per event id.
 *
 * The done marker is written only after every listener returned, so a listener
 * failure leaves the event unmarked and its next delivery is handled again
 * rather than skipped as a duplicate. A cache lock keeps two parallel
 * deliveries of the same event from running the listeners twice; the second
 * one answers 409 and Lunixi delivers it again later. `$locks` is the same
 * cache store when it supports locks; without it only the done marker applies.
 */
final class WebhookProcessor
{
    private const DONE_PREFIX = 'lunixi_wh_done_';
    private const LOCK_PREFIX = 'lunixi_wh_lock_';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ?LockProvider $locks,
        private readonly EventDispatcher $events,
        private readonly BusDispatcher $bus,
        private readonly int $dedupeTtl,
        private readonly int $lockSeconds,
        private readonly QueueSettings $queue
    ) {
    }

    /** Runs the listeners now (sync mode, and the queued job). */
    public function process(WebhookEvent $event): WebhookOutcome
    {
        if ($this->isDone($event)) {
            return WebhookOutcome::Duplicate;
        }

        if ($this->locks === null) {
            return $this->dispatch($event);
        }

        $lock = $this->locks->lock(self::LOCK_PREFIX . self::hash($event), $this->lockSeconds);
        if (!$lock->get()) {
            return WebhookOutcome::InProgress;
        }

        try {
            // Another delivery may have finished between the first check and the lock.
            if ($this->isDone($event)) {
                return WebhookOutcome::Duplicate;
            }

            return $this->dispatch($event);
        } finally {
            $lock->release();
        }
    }

    /** Puts the event on the queue (queue mode); the job calls process(). */
    public function queue(WebhookEvent $event): WebhookOutcome
    {
        if ($this->isDone($event)) {
            return WebhookOutcome::Duplicate;
        }

        $this->bus->dispatch(ProcessWebhookJob::fromEvent($event, $this->queue));

        return WebhookOutcome::Queued;
    }

    private function dispatch(WebhookEvent $event): WebhookOutcome
    {
        $this->events->dispatch(new WebhookReceived($event));
        $this->cache->put(self::DONE_PREFIX . self::hash($event), true, $this->dedupeTtl);

        return WebhookOutcome::Processed;
    }

    /** @phpstan-impure Reads the cache; another delivery may have written the marker meanwhile. */
    private function isDone(WebhookEvent $event): bool
    {
        return (bool) $this->cache->get(self::DONE_PREFIX . self::hash($event), false);
    }

    private static function hash(WebhookEvent $event): string
    {
        return hash('sha256', $event->id());
    }
}
