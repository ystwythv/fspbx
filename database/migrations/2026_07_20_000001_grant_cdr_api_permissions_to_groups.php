<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assign the CDR API permissions (seeded in PR #2/#6) to the standard
 * groups, so the API is usable without a manual v_group_permissions
 * insert on existing installs (issue #15 step 1).
 */
return new class extends Migration
{
    private array $permissionsByGroup = [
        'superadmin' => [
            'cdr_api_read',
            'cdr_api_read_all_domains',
            'api_token_manage',
            'api_token_self_manage',
        ],
        'admin' => [
            'cdr_api_read',
            'api_token_self_manage',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->permissionsByGroup as $groupName => $permissions) {
            // grant to every group with this name (per-domain admin groups included)
            $groups = DB::table('v_groups')
                ->where('group_name', $groupName)
                ->get(['group_uuid', 'group_name']);

            foreach ($groups as $group) {
                foreach ($permissions as $permissionName) {
                    $exists = DB::table('v_group_permissions')
                        ->where('group_uuid', $group->group_uuid)
                        ->where('permission_name', $permissionName)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('v_group_permissions')->insert([
                        'group_permission_uuid' => (string) Str::uuid(),
                        'group_uuid' => $group->group_uuid,
                        'group_name' => $group->group_name,
                        'permission_name' => $permissionName,
                        'permission_protected' => 'true',
                        'permission_assigned' => 'true',
                        'insert_date' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissionsByGroup as $groupName => $permissions) {
            DB::table('v_group_permissions')
                ->where('group_name', $groupName)
                ->whereIn('permission_name', $permissions)
                ->delete();
        }
    }
};
