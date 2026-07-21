<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu entries for the tenant self-service API pages:
 * API Tokens (issue #11) and API Webhooks (issue #9).
 */
return new class extends Migration
{
    private array $items = [
        [
            'title' => 'API Tokens',
            'link' => '/api-tokens',
            'order' => 40,
            'description' => 'Self-service CDR API tokens for this account',
        ],
        [
            'title' => 'API Webhooks',
            'link' => '/api-webhooks',
            'order' => 41,
            'description' => 'Signed cdr.finalized webhooks for this account',
        ],
    ];

    public function up(): void
    {
        // Sit next to Account Settings (typically under "Advanced")
        $anchor = DB::table('v_menu_items')
            ->where('menu_item_link', '/account-settings')
            ->first();

        if (! $anchor) {
            $anchor = DB::table('v_menu_items')
                ->where('menu_item_link', '/virtual-receptionists')
                ->first();
        }

        $parentUuid = $anchor?->menu_item_parent_uuid;
        $menuUuid = $anchor?->menu_uuid ?? DB::table('v_menu_items')->value('menu_uuid');

        if (! $menuUuid) {
            return; // no menu system found, skip
        }

        $groups = DB::table('v_groups')
            ->whereIn('group_name', ['superadmin', 'admin'])
            ->pluck('group_uuid', 'group_name');

        foreach ($this->items as $item) {
            $exists = DB::table('v_menu_items')
                ->where('menu_item_link', $item['link'])
                ->exists();

            if ($exists) {
                continue;
            }

            $menuItemUuid = (string) Str::uuid();

            DB::table('v_menu_items')->insert([
                'menu_item_uuid' => $menuItemUuid,
                'menu_uuid' => $menuUuid,
                'menu_item_title' => $item['title'],
                'menu_item_link' => $item['link'],
                'menu_item_category' => 'internal',
                'menu_item_icon' => null,
                'menu_item_parent_uuid' => $parentUuid,
                'menu_item_order' => $item['order'],
                'menu_item_description' => $item['description'],
                'insert_date' => now(),
                'insert_user' => null,
            ]);

            foreach ($groups as $groupName => $groupUuid) {
                DB::table('v_menu_item_groups')->insert([
                    'menu_item_group_uuid' => (string) Str::uuid(),
                    'menu_uuid' => $menuUuid,
                    'menu_item_uuid' => $menuItemUuid,
                    'group_uuid' => $groupUuid,
                    'group_name' => $groupName,
                    'insert_date' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->items as $item) {
            $menuItem = DB::table('v_menu_items')
                ->where('menu_item_link', $item['link'])
                ->first();

            if ($menuItem) {
                DB::table('v_menu_item_groups')
                    ->where('menu_item_uuid', $menuItem->menu_item_uuid)
                    ->delete();

                DB::table('v_menu_items')
                    ->where('menu_item_uuid', $menuItem->menu_item_uuid)
                    ->delete();
            }
        }
    }
};
