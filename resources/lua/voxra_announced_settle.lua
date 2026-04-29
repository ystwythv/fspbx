-- voxra_announced_settle.lua
--
-- Invoked via execute_on_answer on the announced-transfer target leg the
-- moment the target picks up. Reads the conversation session from Redis via
-- a small Laravel internal endpoint and:
--   1. mutes the original peer (so they listen but can't speak to target)
--   2. kills the AI agent leg (its job is done)
--   3. installs a hangup hook on the summoner so when they drop, the peer
--      gets unmuted and is left bridged to the target.
--
-- Args:
--   1. conversation_id

local SCRIPT_NAME = "[voxra_announced_settle.lua]"
local URL    = "http://127.0.0.1/internal/voxra/reception-agent/announced-settle"
local SECRET = "tH0FXyxfG6Kh36*VHYdE4G!gwfE3Pf"

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local function shell_quote(s)
    return "'" .. tostring(s or ""):gsub("'", "'\\''") .. "'"
end

local conversation_id = argv[1] or ""
if conversation_id == "" then
    log("ERR", "missing conversation_id")
    return
end

local payload = string.format('{"conversation_id":"%s"}', conversation_id)

local sig_handle = io.popen(string.format(
    "printf '%%s' %s | openssl dgst -sha256 -hmac %s | sed 's/^.* //'",
    shell_quote(payload), shell_quote(SECRET)
))
local signature = sig_handle:read("*a"):gsub("%s+", "")
sig_handle:close()

local cmd = string.format(
    "curl -k -s --max-time 4 -X POST -H 'Content-Type: application/json' -H 'Signature: %s' -d %s %s",
    signature, shell_quote(payload), shell_quote(URL)
)

log("NOTICE", "settling announced transfer for conv=" .. conversation_id)
local h = io.popen(cmd)
local resp = h and h:read("*a") or ""
if h then h:close() end
log("INFO", "settle response: " .. tostring(resp))
