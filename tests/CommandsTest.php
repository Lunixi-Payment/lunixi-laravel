<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Lunixi\Sdk\Auth\Ed25519Signer;

final class CommandsTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null && is_dir($this->directory)) {
            array_map('unlink', glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_check_fetches_token_and_prints_no_secret(): void
    {
        Http::fake([self::BASE_URL . '/api/v1/auth/token' => Http::response(self::tokenResponse('tok_check_secret'))]);

        $this->artisan('lunixi:check')
            ->expectsOutputToContain('Token fetched')
            ->doesntExpectOutputToContain('tok_check_secret')
            ->doesntExpectOutputToContain('PRIVATE KEY')
            ->assertSuccessful();
    }

    public function test_check_reports_rejected_credentials(): void
    {
        Http::fake([self::BASE_URL . '/api/v1/auth/token' => Http::response(['code' => 'INVALID_SIGNATURE'], 401)]);

        $this->artisan('lunixi:check')
            ->expectsOutputToContain('INVALID_SIGNATURE')
            ->assertFailed();
    }

    public function test_check_warns_when_webhook_secret_is_missing(): void
    {
        config()->set('lunixi.webhooks.secret', null);
        Http::fake([self::BASE_URL . '/api/v1/auth/token' => Http::response(self::tokenResponse())]);

        $this->artisan('lunixi:check')
            ->expectsOutputToContain('LUNIXI_WEBHOOK_SECRET is not set')
            ->assertSuccessful();
    }

    public function test_generate_key_writes_owner_only_file_and_prints_public_key(): void
    {
        $path = $this->tempDirectory() . '/private.pem';

        $this->artisan('lunixi:generate-key', ['--path' => $path])
            ->expectsOutputToContain('BEGIN PUBLIC KEY')
            ->expectsOutputToContain("LUNIXI_PRIVATE_KEY_PATH={$path}")
            ->doesntExpectOutputToContain('BEGIN PRIVATE KEY')
            ->assertSuccessful();

        self::assertSame(0600, fileperms($path) & 0777);
        $signature = (new Ed25519Signer((string) file_get_contents($path)))->sign('probe');
        self::assertNotSame('', $signature);
    }

    public function test_generate_key_refuses_to_overwrite_without_force(): void
    {
        $path = $this->tempDirectory() . '/private.pem';
        file_put_contents($path, 'existing');

        $this->artisan('lunixi:generate-key', ['--path' => $path])->assertFailed();
        self::assertSame('existing', file_get_contents($path));

        $this->artisan('lunixi:generate-key', ['--path' => $path, '--force' => true])->assertSuccessful();
        self::assertStringContainsString('PRIVATE KEY', (string) file_get_contents($path));
    }

    public function test_install_lists_missing_settings_and_webhook_url(): void
    {
        config()->set('lunixi.connections.default.key_id', null);
        config()->set('lunixi.webhooks.secret', null);
        $published = $this->app->configPath('lunixi.php');

        try {
            $this->artisan('lunixi:install')
                ->expectsOutputToContain('LUNIXI_KEY_ID')
                ->expectsOutputToContain('LUNIXI_WEBHOOK_SECRET')
                ->expectsOutputToContain('lunixi/webhook')
                ->assertSuccessful();

            self::assertFileExists($published);
        } finally {
            @unlink($published);
        }
    }

    private function tempDirectory(): string
    {
        $this->directory = sys_get_temp_dir() . '/lunixi-laravel-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);

        return $this->directory;
    }
}
