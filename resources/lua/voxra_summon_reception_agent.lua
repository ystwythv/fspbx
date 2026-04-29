-- voxra_summon_reception_agent.lua
--
-- Invoked from the *9 feature-code dialplan after the originator and peer
-- have been transferred into a fresh conference. POSTs to Laravel's internal
-- summon endpoint, which originates the ElevenLabs reception-agent leg into
-- the same conference and persists the conversation session in Redis so
-- subsequent ElevenLabs tool callbacks can resolve which call to act on.
--
-- Args (in order):
--   1. conf_name                 -- e.g. voxra_recept_<originator_uuid>
--   2. domain_uuid
--   3. originator_uuid
--   4. peer_uuid                 -- may be empty if no peer is bridged
--   5. originator_extension

local SCRIPT_NAME = "[voxra_summon_reception_agent.lua]"
local URL    = "http://127.0.0.1/internal/voxra/reception-agent/summon"
local SECRET = "tH0FXyxfG6Kh36*VHYdE4G!gwfE3Pf"

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local function json_escape(s)
    s = tostring(s or "")
    s = s:gsub('\\', '\\\\'):gsub('"', '\\"')
    return s
end

local function shell_quote(s)
    return "'" .. tostring(s or ""):gsub("'", "'\\''") .. "'"
end

local conf_name             = argv[1] or ""
local domain_uuid           = argv[2] or ""
local originator_uuid       = argv[3] or ""
local peer_uuid             = argv[4] or ""
local originator_extension  = argv[5] or ""

if conf_name == "" or domain_uuid == "" or originator_uuid == "" then
    log("ERR", "missing required args; conf_name/domain_uuid/originator_uuid required")
    return
end

local payload = string.format(
    '{"conf_name":"%s","domain_uuid":"%s","originator_uuid":"%s","peer_uuid":"%s","originator_extension":"%s"}',
    json_escape(conf_name),
    json_escape(domain_uuid),
    json_escape(originator_uuid),
    json_escape(peer_uuid),
    json_escape(originator_extension)
)

local sig_handle = io.popen(string.format(
    "printf '%%s' %s | openssl dgst -sha256 -hmac %s | sed 's/^.* //'",
    shell_quote(payload), shell_quote(SECRET)
))
local signature = sig_handle:read("*a"):gsub("%s+", "")
sig_handle:close()

-- We block briefly waiting on the originate kickoff (~50-150ms) so that the
-- agent leg is on its way before this script returns and the originator's
-- transfer-to-conf executes. Kept synchronous on purpose.
local cmd = string.format(
    "curl -k -s --max-time 4 -X POST -H 'Content-Type: application/json' -H 'Signature: %s' -d %s %s",
    signature, shell_quote(payload), shell_quote(URL)
)

log("NOTICE", "summoning reception agent for conf=" .. conf_name)
local out_handle = io.popen(cmd)
local resp = out_handle and out_handle:read("*a") or ""
if out_handle then out_handle:close() end
log("INFO", "summon response: " .. tostring(resp))
