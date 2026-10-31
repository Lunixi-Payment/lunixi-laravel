<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Support;

use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Maps one `lunixi.connections.*` entry (snake_case, Laravel style) to the
 * SDK's immutable Configuration. Validation stays in the SDK.
 */
final class ConfigurationFactory
{
    public const USER_AGENT = 'lunixi-laravel/0.1.1 lunixi-php-sdk';

    public function __construct(private readonly PrivateKeyResolver $privateKeys)
    {
    }

    /**
     * @param array<string,mixed> $settings
     * @throws ConfigurationException
     */
    public function make(string $connection, array $settings): Configuration
    {
        $options = [
            'baseUrl' => (string) ($settings['base_url'] ?? ''),
            'keyId' => (string) ($settings['key_id'] ?? ''),
            'privateKey' => $this->privateKeys->resolve($connection, $settings),
            'environment' => (string) ($settings['environment'] ?? 'LIVE'),
            'userAgent' => self::USER_AGENT,
        ];

        if (isset($settings['timeout'])) {
            $options['timeout'] = (float) $settings['timeout'];
        }
        if (isset($settings['max_retries'])) {
            $options['maxRetries'] = (int) $settings['max_retries'];
        }
        if (isset($settings['auth_token_path']) && is_string($settings['auth_token_path'])) {
            $options['authTokenPath'] = $settings['auth_token_path'];
        }

        return new Configuration($options);
    }
}
