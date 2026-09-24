-- voxra_screen_call.lua
--
-- Pre-answer spam screening for Voxra (voxragtm#84). Runs as the first routing
-- action of every inbound destination (phone-number-dial-plan-template), i.e.
-- BEFORE the call is sent to the AI agent, the owner's mobile (Line /
-- ring-first) or voicemail — one interception point for every plan.
--
-- Asks Laravel (/internal/voxra/screen-call, HMAC voxra_internal_secret), which
-- asks voxraweb (per-tenant blocklist, cross-tenant spam list, velocity). On
-- "reject" the caller hears a short message over EARLY MEDIA (the call is never
-- answered: no carrier answer, no AI leg, no AI minutes) and is hung up with
-- CALL_REJECTED. Anything else — including every error/timeout — ALLOWS: a
-- screening outage must never block real customers.

local SCRIPT_NAME = "[voxra_screen_call.lua]"
local URL = "http://127.0.0.1/internal/voxra/screen-call"

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

if not session or not session:ready() then return end

-- Once per call (a transfer back into the public context must not re-screen).
if session:getVariable("voxra_screened") == "true" then return end
session:setVariable("voxra_screened", "true")

local api = freeswitch.API()
local SECRET = api:executeString("global_getvar voxra_internal_secret")
if not SECRET or SECRET == "" then
    log("WARNING", "voxra_internal_secret not set - screening skipped")
    return
end

local function json_escape(s)
    s = tostring(s or "")
    s = s:gsub('\\', '\\\\'):gsub('"', '\\"'):gsub('[%c]', '')
    return s
end

local function shell_quote(s)
    return "'" .. tostring(s or ""):gsub("'", "'\\''") .. "'"
end

local domain_uuid = session:getVariable("domain_uuid") or ""
local caller = session:getVariable("caller_id_number") or ""
local dest = session:getVariable("destination_number") or ""
local call_uuid = session:getVariable("uuid") or ""
if domain_uuid == "" then return end

local payload = string.format(
    '{"domain_uuid":"%s","caller_id_number":"%s","destination_number":"%s","call_uuid":"%s"}',
    json_escape(domain_uuid), json_escape(caller), json_escape(dest), json_escape(call_uuid)
)

local sig_handle = io.popen(string.format(
    "printf '%%s' %s | openssl dgst -sha256 -hmac %s | sed 's/^.* //'",
    shell_quote(payload), shell_quote(SECRET)
))
local signature = sig_handle and sig_handle:read("*a"):gsub("%s+", "") or ""
if sig_handle then sig_handle:close() end

local cmd = string.format(
    "curl -s --max-time 3 -X POST -H 'Content-Type: application/json' -H 'Signature: %s' -d %s %s",
    signature, shell_quote(payload), shell_quote(URL)
)
local h = io.popen(cmd)
local resp = h and h:read("*a") or ""
if h then h:close() end

if not resp:find('"action"%s*:%s*"reject"') then
    return -- allow (or screening unavailable → fail open)
end

local reason = resp:match('"reason"%s*:%s*"([%w_]+)"') or "screened"
local prompt = resp:match('"prompt"%s*:%s*"([%w_]+)"') or "rejected"
log("NOTICE", string.format("rejecting call %s from %s to %s (%s)", call_uuid, caller, dest, reason))
session:setVariable("voxra_screen_reason", reason)

-- Voxra's own British-English prompts (Telnyx "Alistair", the assistant's
-- default voice; regenerate with resources/sounds/voxra/generate.sh). Picked at
-- the leg's sample rate; falls back to FreeSWITCH's stock prompt if the file
-- is missing on this host.
local SOUNDS_DIR = "/var/www/fspbx/resources/sounds/voxra"
local STOCK = {
    anonymous = "ivr/ivr-not_accept_anonymous_calls.wav",
    rejected = "ivr/ivr-call_rejected.wav",
}

local function file_exists(path)
    local f = io.open(path, "rb")
    if f then f:close() return true end
    return false
end

local function prompt_file(name)
    local rate = tonumber(session:getVariable("read_rate") or "") or 8000
    local dirs = rate >= 16000 and { "16000", "8000" } or { "8000", "16000" }
    for _, d in ipairs(dirs) do
        local path = string.format("%s/%s/voxra-call-%s.wav", SOUNDS_DIR, d, name)
        if file_exists(path) then return path end
    end
    log("WARNING", "Voxra prompt " .. name .. " missing - using stock prompt")
    return STOCK[name]
end

if session:ready() then
    session:execute("pre_answer")
    session:sleep(300)
    session:execute("playback", prompt_file(prompt == "anonymous" and "anonymous" or "rejected"))
end
session:hangup("CALL_REJECTED")
