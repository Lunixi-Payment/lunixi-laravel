<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Webhooks;

/**
 * Result of handing a verified webhook to the processor, as answered to
 * Lunixi. Lunixi delivers the event again on any non-2xx answer.
 */
enum WebhookOutcome: string
{
    case Processed = 'ok';
    case Duplicate = 'duplicate';
    case Queued = 'queued';
    case InProgress = 'in_progress';

    public function httpStatus(): int
    {
        return $this === self::InProgress ? 409 : 200;
    }
}
