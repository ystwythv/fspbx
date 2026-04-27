-- push_wake.lua
--
-- Fires the APNs VoIP push for an incoming call to a push-enabled extension
-- and waits briefly for the device to re-REGISTER, so local_extension can
-- bridge to it when the iOS app is backgrounded/closed.
--
-- Invoked from the FreeSWITCH dialplan as an early `continue="true"` extension
-- (order < local_extension) so this script runs before the user lookup tries
-- to bridge. If the extension has no apns_voip_token the script returns a
-- no-op and normal dialplan flow is unchanged.
--
-- Flow:
--   1. Resolve extension_uuid and apns_voip_token via user_data API.
--   2. If no token, return — this is a regular SIP phone; nothing to do.
--   3. Fire-and-forget HTTP POST to voxra webhook with event=incoming_call,
--      which triggers SendIncomingCallPushJob → ApnsPushService.
--   4. pre_answer the inbound leg so the caller hears early media (ringback)
--      while we wait for the phone to wake and re-register.
--   5. Poll sofia_contact every 500ms for up to PUSH_WAKE_TIMEOUT_MS; return
--      as soon as a contact appears (local_extension bridge will succeed),
--      or at timeout (falls through to forward_user_not_registered).

local SCRIPT_NAME = "[push_wake.lua]"
local WEBHOOK_URL = "http://127.0.0.1/webhook/freeswitch"
local WEBHOOK_SECRET = "tH0FXyxfG6Kh36*VHYdE4G!gwfE3Pf"
local PUSH_WAKE_TIMEOUT_MS = 15000
local PUSH_WAKE_POLL_MS = 500

local json = require "resources.functions.lunajson"
local api = freeswitch.API()

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local function api_value(cmd)
    local v = api:executeString(cmd)
    if not v then return "" end
    v = v:gsub("%s+$", "")
    -- Treat sofia's user-not-registered response (and related error/* strings)
    -- as "no value" — otherwise api_value returns the error text verbatim and
    -- the sofia_contact poll loop mistakes it for a valid contact URI,
    -- exiting before the push-woken app has re-registered.
    if v == "" or v:match("^%-ERR") or v:match("^error/") or v == "_undef_" then return "" end
    return v
end

local function shell_quote(s)
    return "'" .. tostring(s or ""):gsub("'", "'\\''") .. "'"
end

if not session or not session:ready() then
    return
end

local destination_number = session:getVariable("destination_number") or ""
local domain_name = session:getVariable("domain_name") or ""
local caller_id_name = session:getVariable("caller_id_name") or "Unknown"
local caller_id_number = session:getVariable("caller_id_number") or ""
local call_uuid = session:getVariable("uuid") or ""

-- DID-level caller-id prefix (set by DialplanBuilderService for the inbound
-- route, e.g. "SUPPORT", "SALES"). When present, effective_caller_id_name
-- currently delivers `PREFIX#<name>` in caller_id_name — we lift the prefix
-- into its own payload field and strip it from caller_id_name so the iOS
-- app can render it as a distinct badge instead of parsing the name string.
local did_prefix = session:getVariable("cnam_prefix") or ""
if did_prefix ~= "" then
    -- Escape Lua pattern metacharacters in the prefix before string.match,
    -- so prefixes containing `-`, `.`, `+`, etc. still match literally.
    local escaped = did_prefix:gsub("(%W)", "%%%1")
    local stripped = caller_id_name:match("^" .. escaped .. "#(.*)$")
    if stripped and stripped ~= "" then
        caller_id_name = stripped
    end
end

if destination_number == "" or domain_name == "" then
    return
end

local aor = destination_number .. "@" .. domain_name

local extension_uuid = api_value("user_data " .. aor .. " var extension_uuid")
local apns_token = api_value("user_data " .. aor .. " var apns_voip_token")

-- ring_target controls which device class(es) actually ring this call.
-- Sourced from v_extensions.ring_target via the directory.lua patch in
-- iqm-ansible's voxra/push-wake-dialplan.yml role. Defaults to "both" when
-- the column is null/missing or the patch hasn't yet exposed it.
local ring_target = api_value("user_data " .. aor .. " var ring_target")
if ring_target ~= "app" and ring_target ~= "fmc" and ring_target ~= "both" then
    ring_target = "both"
end

local app_in_ring_set = (ring_target == "app" or ring_target == "both")

-- Classify a registered contact by device class, using the URI parameter
-- emitted at REGISTER time. Falls back to user-agent sniffing for FMC during
-- the transition window before iqm-reg-bot adopts ;device=fmc.
local function classify_contact(contact_uri, user_agent)
    contact_uri = contact_uri or ""
    if contact_uri:match("[;<]device=app") then return "app" end
    if contact_uri:match("[;<]device=fmc") then return "fmc" end
    local ua = (user_agent or ""):lower()
    if ua:match("iqmobile") then return "fmc" end
    return "other"
end

-- Enumerate sip_registrations for this AOR via the FS core DB. Each row is
-- {profile, contact, ua, class}. Empty list if mod_sofia hasn't observed any
-- registrations for this user (likely the extension is new or the WebRTC
-- flush below has just cleared the iPhone's contact).
local function query_contacts()
    local rows = {}
    -- Each sofia profile maintains its own sqlite registration DB at
    -- /dev/shm/sofia_reg_<profile>.db (configured via `odbc-dsn` in
    -- v_sip_profile_settings). The legacy `freeswitch.Dbh("core")` path
    -- opens an unrelated empty core.db and silently returns 0 rows, which
    -- caused the poll loop to exhaust its full 15s timeout on every call
    -- and fall through to local_extension with no parallel-fork bridge.
    -- Iterate the profiles we care about and aggregate.
    local safe_user = destination_number:gsub("'", "''")
    local safe_host = domain_name:gsub("'", "''")
    local sql = string.format(
        "SELECT contact, user_agent FROM sip_registrations WHERE sip_user='%s' AND sip_host='%s'",
        safe_user, safe_host
    )
    for _, profile in ipairs({"internal", "external", "webrtc"}) do
        local dbh = freeswitch.Dbh("sqlite:///dev/shm/sofia_reg_" .. profile .. ".db")
        if dbh then
            dbh:query(sql, function(row)
                table.insert(rows, {
                    profile = profile,
                    contact = row.contact or "",
                    ua = row.user_agent or "",
                    class = classify_contact(row.contact, row.user_agent),
                })
            end)
            dbh:release()
        end
    end
    return rows
end

-- Build a sofia bridge URI from a registration row. Strips the `sip:` /
-- angle-bracket wrapping mod_sofia stores in `contact` and prefixes with
-- `sofia/<profile>/` so the bridge dial-string addresses the right profile.
local function bridge_uri(row)
    local stripped = row.contact
    stripped = stripped:gsub("^<", ""):gsub(">$", "")
    stripped = stripped:gsub("^sips:", ""):gsub("^sip:", "")
    return "sofia/" .. row.profile .. "/" .. stripped
end

-- Wake the iPhone only when the app is in the ring set AND has a push
-- token. For ring_target=fmc we skip the WebRTC flush + APNs push + early
-- media entirely — the FMC registration takes the call without the iPhone
-- being woken. For legacy SIP-only extensions (no apns_token) on
-- ring_target=both, fall through to local_extension as today.
if app_in_ring_set and apns_token ~= "" then
    -- Flush any existing WebRTC registration for this extension before waking
    -- the app. When iOS force-quits, the WSS dies but the sofia registration
    -- persists until expiry — bridge would then target the dead contact, the
    -- caller hears endless ringback and the pushed app gets no SIP INVITE
    -- (answer guard fails). Clearing first forces the woken app to register
    -- fresh; any foreground session briefly reconnects.
    --
    -- Only for ring_target=app: empirically `sofia profile webrtc
    -- flush_inbound_reg <aor> reboot` also evicts the FMC reg-bot's
    -- registration on the internal profile (the reboot NOTIFY is delivered
    -- AOR-wide, not profile-scoped), leaving the bridge with no SIM leg
    -- until the reg-bot's next periodic re-register (~180s). For
    -- ring_target=both we'd rather skip the flush — the FMC reg-bot is the
    -- safety net that always rings the SIM, and a stale WebRTC contact
    -- joining the parallel bridge fails silently while the FMC leg rings.
    if ring_target == "app" then
        api:executeString("sofia profile webrtc flush_inbound_reg " .. aor .. " reboot")
    end

    local payload = json.encode({
        event = "incoming_call",
        timestamp = os.date("!%Y-%m-%dT%H:%M:%SZ"),
        data = {
            extension_uuid = extension_uuid,
            extension_number = destination_number,
            domain_name = domain_name,
            caller_id_name = caller_id_name,
            caller_id_number = caller_id_number,
            call_uuid = call_uuid,
            did_prefix = did_prefix,
            did_e164 = destination_number,
            ring_target = ring_target,
        },
    })

    local hmac_cmd = string.format(
        "printf %%s %s | openssl dgst -sha256 -hmac %s | awk '{print $NF}'",
        shell_quote(payload), shell_quote(WEBHOOK_SECRET)
    )
    local hmac_handle = io.popen(hmac_cmd)
    local signature = hmac_handle and hmac_handle:read("*a") or ""
    if hmac_handle then hmac_handle:close() end
    signature = signature:gsub("%s+", "")

    local curl_cmd = string.format(
        "(curl -k -s -m 5 -X POST -H 'Content-Type: application/json' -H 'Signature: %s' -d %s %s >/dev/null 2>&1) &",
        signature, shell_quote(payload), shell_quote(WEBHOOK_URL)
    )
    os.execute(curl_cmd)
    log("INFO", "dispatched incoming_call webhook for " .. aor)

    -- Force the outbound B-leg (iOS endpoint) to use the A-leg channel UUID as
    -- its SIP Call-ID, matching the push payload's `call_uuid`. Without this,
    -- mod_sofia mints a fresh Call-ID and CallKit's answer UUID (from push)
    -- doesn't align with CallManager's callUUID (from INVITE), so the answer
    -- guard fails and the call is BYE'd.
    if call_uuid ~= "" then
        session:execute("export", "nolocal:sip_invite_call_id=" .. call_uuid)
    end

    if not session:ready() then return end
    session:preAnswer()

    -- For ring_target=app the only acceptable contact is the freshly-woken
    -- iPhone — a deskphone or FMC registration that survived the WebRTC
    -- flush would short-circuit and we'd bridge to the wrong device before
    -- the app has registered.
    -- For ring_target=both we exit on *any* contact: the FMC reg-bot is
    -- typically already registered on the internal profile (the flush
    -- only clears webrtc), so SIM ringing starts within one poll tick
    -- while the iPhone continues to wake in parallel. If the app
    -- registers before the bridge is built it joins the parallel ring;
    -- otherwise SIM rings alone.
    local function ready_to_bridge()
        for _, row in ipairs(query_contacts()) do
            if row.class == "app" or ring_target == "both" then
                return true
            end
        end
        return false
    end

    local deadline_attempts = math.floor(PUSH_WAKE_TIMEOUT_MS / PUSH_WAKE_POLL_MS)
    local attempt = 0
    while attempt < deadline_attempts do
        if not session:ready() then return end
        if ready_to_bridge() then
            log("INFO", string.format("contact ready after %dms (ring_target=%s)",
                attempt * PUSH_WAKE_POLL_MS, ring_target))
            break
        end
        session:sleep(PUSH_WAKE_POLL_MS)
        attempt = attempt + 1
    end
elseif ring_target == "both" and apns_token == "" then
    -- Legacy SIP-only extension on default ring_target → today's behaviour:
    -- let local_extension take over (no wake to do, no filter to apply).
    return
end

-- ring_target=both → ring every registered contact (app + fmc + other) in
--                    parallel; first to answer wins.
-- ring_target=app|fmc → ring the matching device class first, with anything
--                       else as fallback.
-- The bridge string is comma-separated; in FreeSWITCH bridge syntax that's
-- a parallel hunt across all destinations (continue_on_fail below is a
-- harmless no-op on a parallel bridge).
local contacts = query_contacts()
local primary, fallback = {}, {}
for _, row in ipairs(contacts) do
    if ring_target == "both" or row.class == ring_target then
        table.insert(primary, bridge_uri(row))
    else
        table.insert(fallback, bridge_uri(row))
    end
end

if #primary == 0 and #fallback == 0 then
    log("NOTICE", string.format("ring_target=%s but no contacts for %s — fall through", ring_target, aor))
    return
end

local seq = {}
for _, u in ipairs(primary) do table.insert(seq, u) end
for _, u in ipairs(fallback) do table.insert(seq, u) end
local bridge_str = table.concat(seq, ",")

log("INFO", string.format("ring_target=%s primary=%d fallback=%d bridge=%s",
    ring_target, #primary, #fallback, bridge_str))

session:execute("set", "continue_on_fail=USER_BUSY,NO_ANSWER,USER_NOT_REGISTERED,NO_ROUTE_DESTINATION,UNALLOCATED_NUMBER,RECOVERY_ON_TIMER_EXPIRE,CALL_REJECTED")
session:execute("set", "hangup_after_bridge=true")
session:execute("bridge", bridge_str)

-- Defensive: hang up explicitly so the dialplan engine doesn't continue to
-- local_extension after we've handled the call. The push_wake_hook extension
-- has continue="true" — without an explicit hangup we'd ring twice.
if session:ready() then
    session:hangup("NORMAL_CLEARING")
end
