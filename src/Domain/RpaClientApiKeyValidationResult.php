<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Result object for RPA Client API Key verification.
 */
final class RpaClientApiKeyValidationResult
{
    public const ERROR_MISSING_KEY = 'missing_key';
    public const ERROR_INVALID_KEY = 'invalid_key';
    public const ERROR_KEY_REVOKED = 'key_revoked';
    public const ERROR_INSTALLATION_MISMATCH = 'installation_mismatch';

    public function __construct(
        public readonly bool $valid,
        public readonly ?SaClientApiKey $key = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(SaClientApiKey $key): self
    {
        return new self(
            valid: true,
            key: $key,
            errorCode: null,
            errorMessage: null,
        );
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(
            valid: false,
            key: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
