-- Minimal schema for CDR API integration tests (issue #12).
--
-- The full v_xml_cdr table comes from the FusionPBX base schema, which the
-- repo's own migrations only extend — so a fresh test database cannot be
-- built with `artisan migrate`. This file creates just the tables the CDR
-- API request path touches, with the columns the code actually reads.

CREATE TABLE IF NOT EXISTS v_domains (
    domain_uuid uuid PRIMARY KEY,
    domain_name text,
    domain_enabled text DEFAULT 'true',
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS v_users (
    user_uuid uuid PRIMARY KEY,
    domain_uuid uuid,
    username text,
    user_email text,
    password text,
    user_enabled text DEFAULT 'true',
    add_date timestamptz,
    add_user text,
    update_date timestamptz,
    created_at timestamptz,
    updated_at timestamptz
);

-- eager-loaded by the User model ($with)
CREATE TABLE IF NOT EXISTS users_adv_fields (
    user_adv_fields_uuid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_uuid uuid,
    first_name text,
    last_name text,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS v_user_settings (
    user_setting_uuid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_uuid uuid,
    domain_uuid uuid,
    user_setting_category text,
    user_setting_subcategory text,
    user_setting_name text,
    user_setting_value text,
    user_setting_enabled text,
    insert_date timestamptz
);

CREATE TABLE IF NOT EXISTS v_groups (
    group_uuid uuid PRIMARY KEY,
    domain_uuid uuid,
    group_name text,
    group_level int,
    insert_date timestamptz
);

CREATE TABLE IF NOT EXISTS v_group_permissions (
    group_permission_uuid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    group_uuid uuid,
    group_name text,
    permission_name text,
    permission_protected text,
    permission_assigned text,
    insert_date timestamptz
);

CREATE TABLE IF NOT EXISTS v_user_groups (
    user_group_uuid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid uuid,
    group_name text,
    group_uuid uuid,
    user_uuid uuid,
    insert_date timestamptz
);

CREATE TABLE IF NOT EXISTS user_domain_group_permissions (
    id bigserial PRIMARY KEY,
    user_uuid uuid,
    domain_group_uuid uuid,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS domain_group_relations (
    uuid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_group_uuid uuid,
    domain_uuid uuid,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS user_domain_permission (
    id bigserial PRIMARY KEY,
    user_uuid uuid,
    domain_uuid uuid,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tokenable_type varchar(255),
    tokenable_id uuid,
    name varchar(255),
    token varchar(64) UNIQUE,
    abilities text,
    domain_uuid uuid,
    last_used_at timestamptz,
    expires_at timestamptz,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS archive_recording (
    id bigserial PRIMARY KEY,
    xml_cdr_uuid uuid,
    domain_uuid uuid,
    object_key text,
    created_at timestamptz,
    updated_at timestamptz
);

CREATE TABLE IF NOT EXISTS v_xml_cdr (
    xml_cdr_uuid uuid PRIMARY KEY,
    domain_uuid uuid,
    domain_name text,
    extension_uuid uuid,
    sip_call_id text,
    direction text,
    caller_id_name text,
    caller_id_number text,
    caller_destination text,
    destination_number text,
    source_number text,
    start_epoch bigint,
    answer_epoch bigint,
    end_epoch bigint,
    duration int,
    billsec int,
    hangup_cause text,
    hangup_cause_q850 int,
    sip_hangup_disposition text,
    voicemail_message boolean,
    missed_call boolean,
    call_center_queue_uuid uuid,
    cc_side text,
    cc_cancel_reason text,
    cc_cause text,
    cc_member_session_uuid uuid,
    originating_leg_uuid uuid,
    leg text,
    record_path text,
    record_name text,
    pdd_ms int,
    rtp_audio_in_mos numeric(4,2),
    rtp_audio_out_mos numeric(4,2),
    rtp_audio_in_jitter_ms numeric(8,3),
    rtp_audio_in_packet_loss numeric(5,2),
    read_codec text,
    read_rate text,
    write_codec text,
    write_rate text,
    remote_media_ip text,
    network_addr text,
    accountcode text,
    call_flow text,
    call_cost numeric(10,4),
    call_cost_currency char(3),
    call_cost_rate_uuid uuid,
    status text
);
