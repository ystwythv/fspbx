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
--   1. Resolve extension_uuid and apns_voip_token via direct DB query to
--      v_extensions (the column is not exposed via FreeSWITCH user_data).
--   2. If no token, return — this is a regular SIP phone; nothing to do.
--   3. Snapshot the current sofia_contact so we can detect the *new* wake-up
--      registration later (on FMC deployments a reg-bot-controller is always
--      registered at the private VLAN IP, so a non-empty contact alone does
--      not mean the real device is online).
--   4. Fire-and-forget HTTP POST to voxra webhook with event=incoming_call,
--      which triggers SendIncomingCallPushJob → ApnsPushService.
--   5. pre_answer the inbound leg so the caller hears early media (ringback)
--      while we wait for the phone to wake and re-register.
--   6. Poll sofia_contact every 500ms for up to PUSH_WAKE_TIMEOUT_MS; return
--      as soon as a *new* (different) contact appears, or at timeout
--      (falls through to forward_user_not_registered).

local SCRIPT_NAME = "[push_wake.lua]"
local WEBHOOK_URL = "http://127.0.0.1/webhook/freeswitch"
local WEBHOOK_SECRET = "tH0FXyxfG6Kh36*VHYdE4G!gwfE3Pf"
local PUSH_WAKE_TIMEOUT_MS = 15000
local PUSH_WAKE_POLL_MS = 500

local json = require "resources.functions.lunajson"
local Database = require "resources.functions.database"
local api = freeswitch.API()

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local function api_value(cmd)
    local v = api:executeString(cmd)
    if not v then return "" end
    v = v:gsub("%s+$", "")
    if v == "" or v:match("^%-ERR") or v == "_undef_" then return "" end
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

if destination_number == "" or domain_name == "" then
    return
end

local aor = destination_number .. "@" .. domain_name

-- Look up apns_voip_token and extension_uuid directly from v_extensions —
-- fspbx stores these on the extensions table and does not expose
-- apns_voip_token as a FreeSWITCH user variable.
local dbh = Database.new("system")
local apns_token, extension_uuid = "", ""
local lookup_sql = [[
    SELECT e.extension_uuid, COALESCE(e.apns_voip_token, '') AS apns_voip_token
      FROM v_extensions e
      JOIN v_domains d ON d.domain_uuid = e.domain_uuid
     WHERE d.domain_name = :domain_name
       AND e.extension = :extension
     LIMIT 1
]]
dbh:query(lookup_sql, { domain_name = domain_name, extension = destination_number }, function(row)
    extension_uuid = row.extension_uuid or ""
    apns_token = row.apns_voip_token or ""
end)

if apns_token == "" then
    return
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
    },
})

-- Snapshot the pre-push contact so we can detect a *new* registration later.
-- On FMC deployments a reg-bot-controller is always registered against the
-- AOR, so any contact is present from the start.
local contact_before = api_value("sofia_contact */" .. aor)

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

if not session:ready() then return end
session:preAnswer()

local deadline_attempts = math.floor(PUSH_WAKE_TIMEOUT_MS / PUSH_WAKE_POLL_MS)
local attempt = 0
while attempt < deadline_attempts do
    if not session:ready() then return end
    local contact = api_value("sofia_contact */" .. aor)
    if contact ~= "" and contact ~= contact_before then
        log("INFO", string.format("new registration after %dms — handing off to local_extension", attempt * PUSH_WAKE_POLL_MS))
        return
    end
    session:sleep(PUSH_WAKE_POLL_MS)
    attempt = attempt + 1
end

log("NOTICE", "push_wake timeout for " .. aor .. " — falling through to forward_user_not_registered")
