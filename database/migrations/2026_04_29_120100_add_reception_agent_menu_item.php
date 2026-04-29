<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Place under the same parent as the AI Agents menu item.
        $aiAgents = DB::table('v_menu_items')
            ->where('menu_item_link', '/ai-agents')
            ->first();

        $parentUuid = $aiAgents?->menu_item_parent_uuid;
        $menuUuid = $aiAgents?->menu_uuid ?? DB::table('v_menu_items')->value('menu_uuid');

        if (!$menuUuid) {
            return;
        }

        $exists = DB::table('v_menu_items')
            ->where('menu_item_title', 'Reception Agent')
            ->exists();
        if ($exists) {
            return;
        }

        $menuItemUuid = (string) Str::uuid();

        DB::table('v_menu_items')->insert([
            'menu_item_uuid'        => $menuItemUuid,
            'menu_uuid'             => $menuUuid,
            'menu_item_title'       => 'Reception Agent',
            'menu_item_link'        => '/reception-agent',
            'menu_item_category'    => 'internal',
            'menu_item_icon'        => null,
            'menu_item_parent_uuid' => $parentUuid,
            'menu_item_order'       => 16,
            'menu_item_description' => 'Mid-call AI receptionist (*9)',
            'insert_date'           => now(),
            'insert_user'           => null,
        ]);

        $groups = DB::table('v_groups')
            ->whereIn('group_name', ['superadmin', 'admin'])
            ->pluck('group_uuid', 'group_name');

        foreach ($groups as $groupName => $groupUuid) {
            DB::table('v_menu_item_groups')->insert([
                'menu_item_group_uuid' => (string) Str::uuid(),
                'menu_uuid'            => $menuUuid,
                'menu_item_uuid'       => $menuItemUuid,
                'group_uuid'           => $groupUuid,
                'group_name'           => $groupName,
                'insert_date'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        $item = DB::table('v_menu_items')
            ->where('menu_item_title', 'Reception Agent')
            ->where('menu_item_link', '/reception-agent')
            ->first();
        if ($item) {
            DB::table('v_menu_item_groups')->where('menu_item_uuid', $item->menu_item_uuid)->delete();
            DB::table('v_menu_items')->where('menu_item_uuid', $item->menu_item_uuid)->delete();
        }
    }
};
