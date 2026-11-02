<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Authorizes on-demand Account credential retrieval for an RPA Client.
 *
 * A Client may decrypt a password only when:
 * - Client API Key is authenticated (caller responsibility)
 * - Account belongs to the same installation as the key
 * - Browser session belongs to the Client's agent
 * - Browser is bound to the requested Account
 */
final class AccountCredentialGate
{
    public const ERROR_BROWSER_ID_REQUIRED = 'browser_id_required';

    public const ERROR_CLIENT_NOT_REGISTERED = 'client_not_registered';

    public const ERROR_BROWSER_FORBIDDEN = 'browser_forbidden';

    public const ERROR_ACCOUNT_NOT_BOUND = 'account_not_bound_to_browser';

    public const ERROR_INSTALLATION_FORBIDDEN = 'installation_forbidden';

    public const ERROR_ACCOUNT_NOT_FOUND = 'account_not_found';

    /**
     * @return string|null Error code, or null when authorized.
     */
    public function denyReason(
        SaClientApiKey $clientKey,
        ?SaAgent $agent,
        ?SaBrowserSession $session,
        ?SaAccount $account,
        string $browserId,
    ): ?string {
        if (trim($browserId) === '') {
            return self::ERROR_BROWSER_ID_REQUIRED;
        }
        if (! $account instanceof SaAccount) {
            return self::ERROR_ACCOUNT_NOT_FOUND;
        }
        if ((string) $account->installation_id !== (string) $clientKey->installation_id) {
            return self::ERROR_INSTALLATION_FORBIDDEN;
        }
        if (! $agent instanceof SaAgent) {
            return self::ERROR_CLIENT_NOT_REGISTERED;
        }
        if ((int) ($agent->client_api_key_id ?? 0) !== (int) $clientKey->id) {
            return self::ERROR_CLIENT_NOT_REGISTERED;
        }
        if (! $session instanceof SaBrowserSession) {
            return self::ERROR_BROWSER_FORBIDDEN;
        }
        if ((int) $session->agent_id !== (int) $agent->id) {
            return self::ERROR_BROWSER_FORBIDDEN;
        }
        if ($session->account_id === null || (int) $session->account_id !== (int) $account->id) {
            return self::ERROR_ACCOUNT_NOT_BOUND;
        }

        return null;
    }
}
