<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Support;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Reads a connection's Ed25519 private key: from `private_key_path` when set,
 * otherwise from the inline `private_key` value. The key itself never appears
 * in an exception message.
 */
final class PrivateKeyResolver
{
    /**
     * @param \Closure(string):string $basePath Resolves a relative path against the application root.
     */
    public function __construct(private readonly \Closure $basePath)
    {
    }

    /**
     * @param array<string,mixed> $settings
     * @throws ConfigurationException
     */
    public function resolve(string $connection, array $settings): string
    {
        $path = $settings['private_key_path'] ?? null;
        if (is_string($path) && trim($path) !== '') {
            return $this->readFile($connection, $this->absolute(trim($path)));
        }

        $inline = $settings['private_key'] ?? null;
        if (is_string($inline) && trim($inline) !== '') {
            return trim(str_replace('\n', "\n", $inline));
        }

        throw new ConfigurationException(
            "Lunixi connection [{$connection}] has no private key. Set LUNIXI_PRIVATE_KEY_PATH (or private_key_path in config/lunixi.php)."
        );
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return ($this->basePath)($path);
    }

    private function readFile(string $connection, string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException(
                "Lunixi private key file for connection [{$connection}] could not be read: {$path}"
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new ConfigurationException(
                "Lunixi private key file for connection [{$connection}] is empty: {$path}"
            );
        }

        return $contents;
    }
}
