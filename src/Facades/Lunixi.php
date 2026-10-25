<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Lunixi\Laravel\LunixiManager;

/**
 * @method static \Lunixi\Sdk\LunixiClient connection(?string $name = null)
 * @method static string defaultConnection()
 * @method static \Lunixi\Sdk\Payment\PaymentClient payments()
 * @method static \Lunixi\Sdk\Payment\PaymentLinkClient paymentLinks()
 * @method static \Lunixi\Sdk\Subscription\SubscriptionClient subscriptions()
 * @method static \Lunixi\Sdk\Fraud\FraudClient fraud()
 * @method static \Lunixi\Sdk\Kyc\KycClient kyc()
 * @method static \Lunixi\Sdk\Sanction\SanctionClient sanction()
 * @method static \Lunixi\Sdk\Marketplace\MarketplaceClient marketplace()
 * @method static \Lunixi\Sdk\Identity\IdentityClient identity()
 * @method static \Lunixi\Sdk\Wallet\WalletClient wallet()
 * @method static \Lunixi\Sdk\BankConnect\BankConnectClient bankConnect()
 * @method static \Lunixi\Sdk\Reference\ReferenceClient reference()
 * @method static \Lunixi\Sdk\Insurance\InsuranceClient insurance()
 * @method static \Lunixi\Sdk\Auth\TokenManager tokens()
 * @method static \Lunixi\Sdk\Configuration config()
 *
 * @see LunixiManager
 * @see \Lunixi\Sdk\LunixiClient
 */
final class Lunixi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LunixiManager::class;
    }
}
