<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Webhooks;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use Psr\Log\LoggerInterface;

/**
 * Receives Lunixi webhooks: verifies the signature with the SDK (fail-closed),
 * then hands the event to the processor in sync or queue mode.
 *
 * Answers follow the WordPress plugin's contract: 503 not configured, 400
 * invalid signature, 200 ok / duplicate / queued, 409 in progress, 500 when a
 * listener failed (Lunixi delivers the same event id again on any non-2xx).
 * There is no session or CSRF on this route; the signature is the
 * authentication.
 */
final class WebhookController
{
    public const MODE_SYNC = 'sync';
    public const MODE_QUEUE = 'queue';

    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly WebhookProcessor $processor,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) $this->config->get('lunixi.webhooks.secret', '');
        if ($secret === '') {
            $this->logger->error('Lunixi webhook received but LUNIXI_WEBHOOK_SECRET is not set.');

            return new JsonResponse(['error' => 'not_configured'], 503);
        }

        $mode = $this->mode();
        if ($mode !== self::MODE_SYNC && $mode !== self::MODE_QUEUE) {
            $this->logger->error("Lunixi webhook mode must be 'sync' or 'queue'.", ['mode' => $mode]);

            return new JsonResponse(['error' => 'not_configured'], 503);
        }

        try {
            $event = $this->verifier->verify(
                $request->getContent(),
                self::headers($request),
                $secret,
                $this->tolerance()
            );
        } catch (WebhookVerificationException $e) {
            $this->logger->warning('Lunixi webhook rejected.', ['reason' => $e->getMessage()]);

            return new JsonResponse(['error' => 'invalid_signature'], 400);
        }

        try {
            $outcome = $mode === self::MODE_QUEUE
                ? $this->processor->queue($event)
                : $this->processor->process($event);
        } catch (\Throwable $e) {
            $this->logger->error('Lunixi webhook processing failed.', [
                'event_id' => $event->id(),
                'event_type' => $event->type(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'processing_failed'], 500);
        }

        return new JsonResponse(['status' => $outcome->value], $outcome->httpStatus());
    }

    private function mode(): string
    {
        return (string) $this->config->get('lunixi.webhooks.mode', self::MODE_SYNC);
    }

    private function tolerance(): ?int
    {
        $tolerance = $this->config->get('lunixi.webhooks.tolerance', WebhookVerifier::DEFAULT_TOLERANCE_SECONDS);

        return $tolerance === null ? null : (int) $tolerance;
    }

    /** @return array<string,string> */
    private static function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
