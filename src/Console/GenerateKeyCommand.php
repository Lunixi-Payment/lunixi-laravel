<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Console;

use Illuminate\Console\Command;
use Lunixi\Sdk\Auth\Ed25519Signer;

/**
 * Creates an Ed25519 key pair with the SDK, writes the private key to a file
 * readable only by its owner and prints the public key to register in the
 * Lunixi dashboard. Never overwrites an existing key unless --force is given.
 */
final class GenerateKeyCommand extends Command
{
    protected $signature = 'lunixi:generate-key
        {--path= : Private key file (default: storage/lunixi/private.pem)}
        {--force : Overwrite an existing private key file}';

    protected $description = 'Generate a Lunixi Ed25519 key pair';

    public function handle(): int
    {
        $option = $this->option('path');
        $path = is_string($option) && $option !== '' ? $option : $this->laravel->storagePath('lunixi/private.pem');

        if (file_exists($path) && !$this->option('force')) {
            $this->error("{$path} already exists. Use --force to replace it; the old key stops working in Lunixi.");

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $keys = Ed25519Signer::generateKeyPair();

        $previousUmask = umask(0077);
        try {
            $written = file_put_contents($path, $keys['privateKey'], LOCK_EX);
        } finally {
            umask($previousUmask);
        }
        if ($written === false || !chmod($path, 0600)) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->info("Private key written to {$path} (owner read/write only). Keep it out of version control.");
        $this->newLine();
        $this->line('Register this public key in the Lunixi dashboard:');
        $this->newLine();
        $this->line($keys['publicKey']);
        $this->newLine();
        $this->line('Then set in .env:');
        $this->line("LUNIXI_PRIVATE_KEY_PATH={$path}");
        $this->line('LUNIXI_KEY_ID=<key id shown in the dashboard>');

        return self::SUCCESS;
    }
}
