<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mint a CDR API bearer token from the CLI, replacing the tinker recipe
 * in issue #15 step 2. The plain-text token is printed once and is
 * unrecoverable afterwards.
 */
class MintApiToken extends Command
{
    protected $signature = 'api:mint-token
        {--user= : Username or email of the owning user}
        {--name= : Token name, e.g. "peter-admin-global"}
        {--type=global : "global" or "tenant"}
        {--domain_uuid= : Domain UUID (required for tenant tokens)}
        {--abilities= : Comma-separated abilities (defaults by type)}
        {--expires= : Optional expiry, ISO 8601 or relative like "+90 days"}';

    protected $description = 'Mint a CDR API token for a user (prints the plain-text token once)';

    public function handle(): int
    {
        $username = trim((string) $this->option('user'));
        $name = trim((string) $this->option('name'));
        $type = strtolower(trim((string) $this->option('type')));
        $domainUuid = trim((string) $this->option('domain_uuid'));

        if ($username === '' || $name === '') {
            $this->error('Both --user and --name are required.');
            return self::FAILURE;
        }

        if (! in_array($type, ['global', 'tenant'], true)) {
            $this->error('--type must be "global" or "tenant".');
            return self::FAILURE;
        }

        if ($type === 'tenant') {
            if ($domainUuid === '') {
                $this->error('--domain_uuid is required for tenant tokens.');
                return self::FAILURE;
            }
            if (! Domain::query()->where('domain_uuid', $domainUuid)->exists()) {
                $this->error("Domain {$domainUuid} not found.");
                return self::FAILURE;
            }
        } elseif ($domainUuid !== '') {
            $this->error('--domain_uuid must not be set for global tokens.');
            return self::FAILURE;
        }

        $user = User::query()
            ->where('username', $username)
            ->orWhere('user_email', $username)
            ->first();

        if (! $user) {
            $this->error("User {$username} not found.");
            return self::FAILURE;
        }

        $abilities = $this->resolveAbilities($type);
        if ($type === 'tenant' && in_array('cdr:all-domains', $abilities, true)) {
            $this->error('Tenant tokens may not carry the cdr:all-domains ability.');
            return self::FAILURE;
        }

        $expiresAt = null;
        if (($expiresRaw = trim((string) $this->option('expires'))) !== '') {
            try {
                $expiresAt = Carbon::parse($expiresRaw);
            } catch (\Throwable $e) {
                $this->error("Could not parse --expires \"{$expiresRaw}\".");
                return self::FAILURE;
            }
            if ($expiresAt->isPast()) {
                $this->error('--expires must be in the future.');
                return self::FAILURE;
            }
        }

        $newToken = $user->createToken($name, $abilities, $expiresAt);

        if ($type === 'tenant') {
            $newToken->accessToken->forceFill(['domain_uuid' => $domainUuid])->save();
        }

        $this->table(['field', 'value'], [
            ['token id', (string) $newToken->accessToken->id],
            ['user', $user->username],
            ['type', $type],
            ['domain_uuid', $type === 'tenant' ? $domainUuid : '(global)'],
            ['abilities', implode(', ', $abilities)],
            ['expires_at', $expiresAt?->toIso8601ZuluString() ?? '(never)'],
        ]);

        $this->newLine();
        $this->warn('Plain-text token (store it now, it cannot be recovered):');
        $this->line($newToken->plainTextToken);

        return self::SUCCESS;
    }

    private function resolveAbilities(string $type): array
    {
        $raw = trim((string) $this->option('abilities'));
        if ($raw === '') {
            return $type === 'global' ? ['cdr:read', 'cdr:all-domains'] : ['cdr:read'];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw))
        )));
    }
}
