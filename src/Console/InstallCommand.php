<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Console;

use Illuminate\Console\Command;

/**
 * Publishes config/lunixi.php and lists what is still missing for the default
 * connection. Writes nothing to .env.
 */
final class InstallCommand extends Command
{
    protected $signature = 'lunixi:install {--force : Overwrite an existing config/lunixi.php}';

    protected $description = 'Publish the Lunixi configuration and show the remaining setup steps';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'lunixi-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $config = $this->laravel->make('config');
        $default = (string) $config->get('lunixi.default', 'default');
        $connection = (array) $config->get("lunixi.connections.{$default}", []);

        $missing = [];
        if (!self::filled($connection['key_id'] ?? null)) {
            $missing[] = 'LUNIXI_KEY_ID';
        }
        if (!self::filled($connection['private_key_path'] ?? null) && !self::filled($connection['private_key'] ?? null)) {
            $missing[] = 'LUNIXI_PRIVATE_KEY_PATH (run php artisan lunixi:generate-key)';
        }
        if (!self::filled($config->get('lunixi.webhooks.secret'))) {
            $missing[] = 'LUNIXI_WEBHOOK_SECRET';
        }

        if ($missing === []) {
            $this->info('Configuration complete. Run php artisan lunixi:check to test the connection.');
        } else {
            $this->warn('Set these in .env:');
            foreach ($missing as $name) {
                $this->line("  - {$name}");
            }
        }

        if ($this->laravel->make('router')->has('lunixi.webhook')) {
            $this->line('Webhook URL to register in the Lunixi dashboard: ' . $this->laravel->make('url')->route('lunixi.webhook'));
        }

        return self::SUCCESS;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
