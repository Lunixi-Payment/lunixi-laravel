<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Console;

use Illuminate\Console\Command;
use Lunixi\Laravel\LunixiManager;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\LunixiException;

/**
 * Checks a connection end to end: reads the configuration, loads the private
 * key and fetches a new bearer token from Lunixi. Prints no secret.
 */
final class CheckCommand extends Command
{
    protected $signature = 'lunixi:check {--connection= : Lunixi connection name}';

    protected $description = 'Check the Lunixi configuration and fetch a token';

    public function handle(LunixiManager $lunixi): int
    {
        $option = $this->option('connection');
        $name = is_string($option) && $option !== '' ? $option : $lunixi->defaultConnection();

        try {
            $client = $lunixi->connection($name);
            $config = $client->config();
            $client->tokens()->getToken(true);
        } catch (LunixiException $e) {
            $this->error("Connection [{$name}] failed: " . $e->getMessage());
            if ($e instanceof ApiException && $e->getRequestId() !== null) {
                $this->line('Request id: ' . $e->getRequestId());
            }

            return self::FAILURE;
        }

        $this->table(['Connection', 'Environment', 'Base URL', 'Key id'], [[
            $name,
            $config->environment(),
            $config->baseUrl(),
            $config->keyId(),
        ]]);
        $this->info('Token fetched. The connection works.');

        $secret = (string) $this->laravel->make('config')->get('lunixi.webhooks.secret', '');
        if ($secret === '') {
            $this->warn('LUNIXI_WEBHOOK_SECRET is not set; webhooks will be answered with 503.');
        }

        return self::SUCCESS;
    }
}
