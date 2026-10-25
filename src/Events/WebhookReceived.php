<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Events;

use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * A Lunixi webhook whose signature has been verified.
 *
 * Filter by type in your listener — `$event->webhook->matches('payment.*')` —
 * using the event name constants of the SDK (InsuranceEvents,
 * SubscriptionEvents, KycEvents …). Delivery is at least once: a listener must give the
 * same result when it receives the same event id again.
 */
final class WebhookReceived
{
    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
