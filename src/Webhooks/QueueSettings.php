<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Webhooks;

/**
 * Queue placement and retry policy of ProcessWebhookJob, read from
 * `lunixi.webhooks.queue`.
 */
final class QueueSettings
{
    /**
     * @param list<int> $backoff Seconds between attempts.
     */
    public function __construct(
        public readonly ?string $connection,
        public readonly ?string $name,
        public readonly int $tries,
        public readonly array $backoff
    ) {
    }

    /** @param array<string,mixed> $settings */
    public static function fromArray(array $settings): self
    {
        $connection = $settings['connection'] ?? null;
        $name = $settings['name'] ?? null;
        $backoff = $settings['backoff'] ?? [];

        return new self(
            is_string($connection) && $connection !== '' ? $connection : null,
            is_string($name) && $name !== '' ? $name : null,
            max(1, (int) ($settings['tries'] ?? 10)),
            array_values(array_map('intval', is_array($backoff) ? $backoff : [(int) $backoff]))
        );
    }
}
