<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keep update_date current on v_extensions and v_extension_settings (most
 * writers never set it), and treat a change to an extension's settings as a
 * change to the extension. voxra:expire-stale-cache compares these against
 * the FusionPBX file cache so a PBX whose cache was written before a change
 * on the other PBX (eu1 subscribes to lon1's DB) drops it (voxragtm#194).
 * Triggers fire where the write happens (lon1); the timestamps replicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION voxra_touch_update_date() RETURNS trigger AS $$
BEGIN
    NEW.update_date := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION voxra_touch_parent_extension() RETURNS trigger AS $$
BEGIN
    UPDATE v_extensions SET update_date = now()
     WHERE extension_uuid = COALESCE(NEW.extension_uuid, OLD.extension_uuid);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS voxra_touch_update_date ON v_extensions;
CREATE TRIGGER voxra_touch_update_date BEFORE INSERT OR UPDATE ON v_extensions
    FOR EACH ROW EXECUTE FUNCTION voxra_touch_update_date();

DROP TRIGGER IF EXISTS voxra_touch_update_date ON v_extension_settings;
CREATE TRIGGER voxra_touch_update_date BEFORE INSERT OR UPDATE ON v_extension_settings
    FOR EACH ROW EXECUTE FUNCTION voxra_touch_update_date();

DROP TRIGGER IF EXISTS voxra_touch_parent_extension ON v_extension_settings;
CREATE TRIGGER voxra_touch_parent_extension AFTER INSERT OR UPDATE OR DELETE ON v_extension_settings
    FOR EACH ROW EXECUTE FUNCTION voxra_touch_parent_extension();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS voxra_touch_parent_extension ON v_extension_settings;
DROP TRIGGER IF EXISTS voxra_touch_update_date ON v_extension_settings;
DROP TRIGGER IF EXISTS voxra_touch_update_date ON v_extensions;
DROP FUNCTION IF EXISTS voxra_touch_parent_extension();
DROP FUNCTION IF EXISTS voxra_touch_update_date();
SQL);
    }
};
