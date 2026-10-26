<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Omnichannel\Addons\SocialAutomation\Contracts\SaAccountRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

final class SaAccountService
{
    public function __construct(
        private readonly SaAccountRepositoryContract $repository,
        private readonly SocialAutomationInstallationResolver $installationResolver,
    ) {}

    /**
     * @return Collection<int, SaAccount>
     */
    public function list(?string $installationId = null): Collection
    {
        $resolvedId = $installationId ?: $this->installationResolver->resolveInstallationId();

        return $this->repository->list($resolvedId);
    }

    public function find(int $id, ?string $installationId = null): ?SaAccount
    {
        $resolvedId = $installationId ?: $this->installationResolver->resolveInstallationId();

        return $this->repository->find($id, $resolvedId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $userId = null, ?string $installationId = null): SaAccount
    {
        $resolvedId = $installationId ?: $this->installationResolver->resolveInstallationId();

        $name = trim((string) ($data['name'] ?? $data['account_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Account name is required.');
        }

        $platform = trim((string) ($data['platform'] ?? 'Facebook'));
        if ($platform === '') {
            $platform = 'Facebook';
        }

        $username = isset($data['username']) && trim((string) $data['username']) !== ''
            ? trim((string) $data['username'])
            : (isset($data['declared_username']) && trim((string) $data['declared_username']) !== ''
                ? trim((string) $data['declared_username'])
                : null);

        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? trim((string) $data['email'])
            : (isset($data['declared_email']) && trim((string) $data['declared_email']) !== ''
                ? trim((string) $data['declared_email'])
                : null);
        if ($email === null) {
            throw new InvalidArgumentException('Email is required.');
        }

        $siteUrl = isset($data['site_url']) && trim((string) $data['site_url']) !== ''
            ? trim((string) $data['site_url'])
            : null;

        $rawPassword = isset($data['password']) && is_string($data['password']) && trim($data['password']) !== ''
            ? trim($data['password'])
            : null;

        $encryptedPassword = $rawPassword !== null ? $this->encryptPassword($rawPassword) : null;

        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : null;

        $attributes = [
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $resolvedId,
            'platform' => $platform,
            'name' => $name,
            'label' => $name,
            'username' => $username,
            'declared_username' => $username,
            'email' => $email,
            'declared_email' => $email,
            'site_url' => $siteUrl,
            'encrypted_password' => $encryptedPassword,
            'note' => $note,
            'created_by' => $userId,
            'identity_source' => 'inventory',
            'verified' => false,
        ];

        return $this->repository->create($attributes);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, ?string $installationId = null): ?SaAccount
    {
        $resolvedId = $installationId ?: $this->installationResolver->resolveInstallationId();

        $account = $this->repository->find($id, $resolvedId);
        if (! $account instanceof SaAccount) {
            return null;
        }

        $attributes = [];

        if (array_key_exists('platform', $data)) {
            $platform = trim((string) $data['platform']);
            if ($platform !== '') {
                $attributes['platform'] = $platform;
            }
        }

        if (array_key_exists('name', $data) || array_key_exists('account_name', $data)) {
            $name = trim((string) ($data['name'] ?? $data['account_name'] ?? ''));
            if ($name !== '') {
                $attributes['name'] = $name;
                $attributes['label'] = $name;
            }
        }

        if (array_key_exists('username', $data) || array_key_exists('declared_username', $data)) {
            $u = $data['username'] ?? $data['declared_username'] ?? null;
            $username = $u !== null && trim((string) $u) !== '' ? trim((string) $u) : null;
            $attributes['username'] = $username;
            $attributes['declared_username'] = $username;
        }

        if (array_key_exists('email', $data) || array_key_exists('declared_email', $data)) {
            $e = $data['email'] ?? $data['declared_email'] ?? null;
            $email = $e !== null && trim((string) $e) !== '' ? trim((string) $e) : null;
            if ($email === null) {
                throw new InvalidArgumentException('Email is required.');
            }
            $attributes['email'] = $email;
            $attributes['declared_email'] = $email;
        }

        if (array_key_exists('site_url', $data)) {
            $s = $data['site_url'];
            $siteUrl = $s !== null && trim((string) $s) !== '' ? trim((string) $s) : null;
            $attributes['site_url'] = $siteUrl;
        }

        if (array_key_exists('note', $data)) {
            $note = $data['note'] !== null && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;
            $attributes['note'] = $note;
        }

        // Rule D: If password is empty or not provided, preserve existing password!
        if (array_key_exists('password', $data)) {
            $rawPassword = $data['password'];
            if (is_string($rawPassword) && trim($rawPassword) !== '') {
                $attributes['encrypted_password'] = $this->encryptPassword(trim($rawPassword));
            }
        }

        if ($attributes === []) {
            return $account;
        }

        return $this->repository->update($id, $resolvedId, $attributes);
    }

    public function delete(int $id, ?string $installationId = null): bool
    {
        $resolvedId = $installationId ?: $this->installationResolver->resolveInstallationId();

        return $this->repository->delete($id, $resolvedId);
    }

    public function encryptPassword(string $password): string
    {
        if (function_exists('app') && app()->bound('encrypter')) {
            try {
                return Crypt::encryptString($password);
            } catch (\Throwable) {
                // Test or unbooted container fallback
            }
        }

        return 'enc:' . base64_encode($password);
    }

    public function decryptPassword(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        if (str_starts_with($encrypted, 'enc:')) {
            $decoded = base64_decode(substr($encrypted, 4), true);

            return $decoded !== false ? $decoded : null;
        }

        if (function_exists('app') && app()->bound('encrypter')) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
