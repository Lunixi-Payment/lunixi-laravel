<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * Handles a webhook on the queue (`lunixi.webhooks.mode` = queue).
 *
 * The signature was verified when the request arrived; it is not checked
 * again here because the timestamp tolerance has passed by then. A listener
 * failure fails the job and the queue retries it; an event already being
 * handled elsewhere is released back to the queue.
 */
final class ProcessWebhookJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $envelope
     * @param list<int>           $backoff
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $timestamp,
        public readonly array $data,
        public readonly array $envelope,
        int $tries,
        array $backoff
    ) {
        $this->tries = $tries;
        $this->backoff = $backoff;
    }

    public static function fromEvent(WebhookEvent $event, QueueSettings $settings): self
    {
        $job = new self(
            $event->id(),
            $event->type(),
            $event->timestamp(),
            $event->data(),
            $event->envelope(),
            $settings->tries,
            $settings->backoff
        );

        if ($settings->connection !== null) {
            $job->onConnection($settings->connection);
        }
        if ($settings->name !== null) {
            $job->onQueue($settings->name);
        }

        return $job;
    }

    public function handle(WebhookProcessor $processor): void
    {
        $outcome = $processor->process($this->event());

        if ($outcome === WebhookOutcome::InProgress) {
            $this->release($this->backoff[0] ?? 10);
        }
    }

    public function event(): WebhookEvent
    {
        return new WebhookEvent($this->eventId, $this->eventType, $this->timestamp, $this->data, $this->envelope);
    }
}
