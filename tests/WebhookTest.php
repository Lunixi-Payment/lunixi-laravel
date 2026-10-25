<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Lunixi\Laravel\Events\WebhookReceived;
use Lunixi\Laravel\Testing\SignsLunixiWebhooks;
use Lunixi\Laravel\Webhooks\ProcessWebhookJob;
use Lunixi\Laravel\Webhooks\WebhookProcessor;
use Lunixi\Sdk\Webhook\WebhookVerifier;

final class WebhookTest extends TestCase
{
    use SignsLunixiWebhooks;

    public function test_valid_webhook_fires_event_with_verified_payload(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postLunixiWebhook('payment.captured', ['paymentId' => 'pay_1'], 'evt_1')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e): bool => $e->webhook->id() === 'evt_1'
            && $e->webhook->type() === 'payment.captured'
            && $e->webhook->matches('payment.*')
            && $e->webhook->data() === ['paymentId' => 'pay_1']);
    }

    public function test_missing_secret_answers_503(): void
    {
        config()->set('lunixi.webhooks.secret', null);

        $this->postLunixiWebhook('payment.captured', [], null, 'any')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'not_configured']);
    }

    public function test_unknown_mode_answers_503(): void
    {
        config()->set('lunixi.webhooks.mode', 'async');

        $this->postLunixiWebhook('payment.captured')->assertStatus(503);
    }

    public function test_wrong_secret_answers_400_and_fires_nothing(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postLunixiWebhook('payment.captured', [], null, 'whsec_wrong')
            ->assertStatus(400)
            ->assertExactJson(['error' => 'invalid_signature']);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_tampered_body_answers_400(): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signed = '{"id":"evt_t","type":"payment.captured","data":{"amount":100}}';
        $sent = '{"id":"evt_t","type":"payment.captured","data":{"amount":1}}';

        $this->call('POST', route('lunixi.webhook'), [], [], [], $this->transformHeadersToServerVars([
            WebhookVerifier::HEADER_EVENT_ID => 'evt_t',
            WebhookVerifier::HEADER_TIMESTAMP => $timestamp,
            WebhookVerifier::HEADER_SIGNATURE => 'v2=' . hash_hmac('sha256', "evt_t.{$timestamp}.{$signed}", self::WEBHOOK_SECRET),
        ]), $sent)->assertStatus(400);
    }

    public function test_stale_timestamp_answers_400(): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $body = '{"id":"evt_old","type":"payment.captured","data":{}}';

        $this->call('POST', route('lunixi.webhook'), [], [], [], $this->transformHeadersToServerVars([
            WebhookVerifier::HEADER_EVENT_ID => 'evt_old',
            WebhookVerifier::HEADER_TIMESTAMP => $timestamp,
            WebhookVerifier::HEADER_SIGNATURE => 'v2=' . hash_hmac('sha256', "evt_old.{$timestamp}.{$body}", self::WEBHOOK_SECRET),
        ]), $body)->assertStatus(400);
    }

    public function test_rotation_header_with_two_signatures_is_accepted(): void
    {
        Event::fake([WebhookReceived::class]);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $body = '{"id":"evt_rot","type":"payment.captured","data":{}}';
        $old = hash_hmac('sha256', "evt_rot.{$timestamp}.{$body}", 'whsec_old');
        $new = hash_hmac('sha256', "evt_rot.{$timestamp}.{$body}", self::WEBHOOK_SECRET);

        $this->call('POST', route('lunixi.webhook'), [], [], [], $this->transformHeadersToServerVars([
            WebhookVerifier::HEADER_EVENT_ID => 'evt_rot',
            WebhookVerifier::HEADER_TIMESTAMP => $timestamp,
            WebhookVerifier::HEADER_SIGNATURE => "v2={$old}, v2={$new}",
        ]), $body)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function test_redelivery_of_processed_event_is_duplicate(): void
    {
        $calls = 0;
        Event::listen(WebhookReceived::class, function () use (&$calls): void {
            $calls++;
        });

        $this->postLunixiWebhook('payment.captured', [], 'evt_dup')->assertOk();
        $this->postLunixiWebhook('payment.captured', [], 'evt_dup')
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate']);

        self::assertSame(1, $calls);
    }

    public function test_listener_failure_answers_500_and_redelivery_is_processed(): void
    {
        $fail = true;
        $handled = 0;
        Event::listen(WebhookReceived::class, function () use (&$fail, &$handled): void {
            if ($fail) {
                throw new \RuntimeException('database down');
            }
            $handled++;
        });

        $this->postLunixiWebhook('payment.captured', [], 'evt_retry')
            ->assertStatus(500)
            ->assertExactJson(['error' => 'processing_failed']);

        $fail = false;
        $this->postLunixiWebhook('payment.captured', [], 'evt_retry')->assertOk()->assertExactJson(['status' => 'ok']);

        self::assertSame(1, $handled);
    }

    public function test_event_being_processed_elsewhere_answers_409(): void
    {
        $store = Cache::store()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);
        $store->lock('lunixi_wh_lock_' . hash('sha256', 'evt_busy'), 60)->get();

        $this->postLunixiWebhook('payment.captured', [], 'evt_busy')
            ->assertStatus(409)
            ->assertExactJson(['status' => 'in_progress']);
    }

    public function test_route_has_no_session_or_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('lunixi.webhook');

        self::assertNotNull($route);
        self::assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
        self::assertNotContains('web', $route->gatherMiddleware());
    }

    public function test_queue_mode_answers_queued_and_pushes_job(): void
    {
        config()->set('lunixi.webhooks.mode', 'queue');
        config()->set('lunixi.webhooks.queue.name', 'lunixi');
        $this->app->forgetInstance(WebhookProcessor::class);
        Queue::fake();

        $this->postLunixiWebhook('payment.captured', ['paymentId' => 'pay_q'], 'evt_q')
            ->assertOk()
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushedOn('lunixi', ProcessWebhookJob::class, fn (ProcessWebhookJob $job): bool => $job->eventId === 'evt_q'
            && $job->data === ['paymentId' => 'pay_q']
            && $job->tries === 10);
    }

    public function test_queued_job_fires_event_once(): void
    {
        config()->set('lunixi.webhooks.mode', 'queue');
        $this->app->forgetInstance(WebhookProcessor::class);
        $calls = 0;
        Event::listen(WebhookReceived::class, function (WebhookReceived $e) use (&$calls): void {
            self::assertSame('evt_job', $e->webhook->id());
            $calls++;
        });

        $this->postLunixiWebhook('payment.captured', [], 'evt_job')->assertExactJson(['status' => 'queued']);
        $this->postLunixiWebhook('payment.captured', [], 'evt_job')->assertExactJson(['status' => 'duplicate']);

        self::assertSame(1, $calls);
    }

    public function test_route_can_be_disabled_and_registered_manually(): void
    {
        Route::lunixiWebhooks('hooks/lunixi-custom');
        Route::getRoutes()->refreshNameLookups();
        Event::fake([WebhookReceived::class]);

        $this->postLunixiWebhook('payment.captured', [], 'evt_custom', null, '/hooks/lunixi-custom')->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }
}
