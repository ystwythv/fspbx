<?php

namespace Tests\Integration;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Base class for DB-backed CDR API tests (issue #12).
 *
 * Requires a disposable Postgres database (pgsql_testing connection) and
 * the CDR_TEST_DB=true env flag; skips itself otherwise, so the suite is
 * safe to run anywhere. Schema comes from fixtures/cdr-schema.sql because
 * v_xml_cdr belongs to the FusionPBX base schema that repo migrations
 * only extend.
 */
abstract class CdrIntegrationTestCase extends TestCase
{
    private static bool $schemaLoaded = false;

    private array $truncateTables = [
        'v_xml_cdr',
        'personal_access_tokens',
        'v_users',
        'v_domains',
        'v_groups',
        'v_group_permissions',
        'v_user_groups',
        'user_domain_group_permissions',
        'domain_group_relations',
        'user_domain_permission',
        'archive_recording',
        'users_adv_fields',
        'v_user_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('CDR_TEST_DB') !== 'true' && env('CDR_TEST_DB') !== true) {
            $this->markTestSkipped('Set CDR_TEST_DB=true with a pgsql_testing database to run integration tests.');
        }

        config(['database.default' => 'pgsql_testing']);
        DB::purge('pgsql_testing');

        if (! self::$schemaLoaded) {
            DB::unprepared(file_get_contents(__DIR__ . '/fixtures/cdr-schema.sql'));
            self::$schemaLoaded = true;
        }

        DB::statement('TRUNCATE ' . implode(', ', $this->truncateTables));
    }

    protected function makeDomain(string $name = null): string
    {
        $uuid = (string) Str::uuid();

        DB::table('v_domains')->insert([
            'domain_uuid' => $uuid,
            'domain_name' => $name ?? 'test-' . substr($uuid, 0, 8) . '.example.com',
            'domain_enabled' => 'true',
        ]);

        return $uuid;
    }

    /**
     * Creates a user in the domain and grants the given permissions via a
     * dedicated group, mirroring the v_group_permissions layout that
     * PermissionService reads.
     */
    protected function makeUser(string $domainUuid, array $permissions = []): User
    {
        $userUuid = (string) Str::uuid();

        DB::table('v_users')->insert([
            'user_uuid' => $userUuid,
            'domain_uuid' => $domainUuid,
            'username' => 'user-' . substr($userUuid, 0, 8),
            'user_email' => 'user-' . substr($userUuid, 0, 8) . '@example.com',
            'user_enabled' => 'true',
        ]);

        if ($permissions !== []) {
            $groupUuid = (string) Str::uuid();
            $groupName = 'test-group-' . substr($groupUuid, 0, 8);

            DB::table('v_groups')->insert([
                'group_uuid' => $groupUuid,
                'group_name' => $groupName,
            ]);

            foreach ($permissions as $permission) {
                DB::table('v_group_permissions')->insert([
                    'group_permission_uuid' => (string) Str::uuid(),
                    'group_uuid' => $groupUuid,
                    'group_name' => $groupName,
                    'permission_name' => $permission,
                    'permission_assigned' => 'true',
                    'permission_protected' => 'false',
                ]);
            }

            DB::table('v_user_groups')->insert([
                'user_group_uuid' => (string) Str::uuid(),
                'domain_uuid' => $domainUuid,
                'group_name' => $groupName,
                'group_uuid' => $groupUuid,
                'user_uuid' => $userUuid,
            ]);
        }

        return User::query()->findOrFail($userUuid);
    }

    /**
     * Mints a bearer token. Pass a domain uuid for a tenant-bound token;
     * null makes a global token.
     */
    protected function mintToken(User $user, ?string $domainUuid, array $abilities = ['cdr:read']): string
    {
        $newToken = $user->createToken('test-token', $abilities);

        if ($domainUuid !== null) {
            $newToken->accessToken->forceFill(['domain_uuid' => $domainUuid])->save();
        }

        return $newToken->plainTextToken;
    }

    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    protected function isoWindowAroundNow(int $daysBack = 1, int $daysForward = 1): string
    {
        return 'date_from=' . gmdate('Y-m-d\TH:i:s\Z', time() - $daysBack * 86400)
            . '&date_to=' . gmdate('Y-m-d\TH:i:s\Z', time() + $daysForward * 86400);
    }
}
