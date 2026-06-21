-- voxra_blind_settle.lua
--
-- Invoked via execute_on_answer on the blind-transfer target leg the moment the
-- target picks up and is in the conference. A blind transfer (the agent was
-- asked "transfer this call to <ext>") means the summoner who pressed *9 is
-- handing the original caller (peer) over and dropping out, and the AI agent's
-- job is done. So once — and only once — the target actually answers, we tear
-- down the agent + originator legs, leaving the peer bridged to the target.
--
-- This MUST be deferred to answer-time rather than done synchronously when the
-- tool fires: the target originate is async (bgapi), so killing the conference
-- members in the same breath races the still-ringing target and FreeSWITCH
-- aborts the new leg (instant 503 — the target never rings).
--
-- Args (positional; "-" means "skip this one"):
--   1. agent leg uuid
--   2. originator (summoner) leg uuid
--   3. conversation_id (informational / future use)

local SCRIPT_NAME = "[voxra_blind_settle.lua]"
local api = freeswitch.API()

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local agent_uuid = argv[1] or "-"
local orig_uuid  = argv[2] or "-"
local conv_id    = argv[3] or "-"

log("NOTICE", "settling blind transfer conv=" .. conv_id ..
    " agent=" .. agent_uuid .. " orig=" .. orig_uuid)

if agent_uuid ~= "" and agent_uuid ~= "-" then
    api:execute("uuid_kill", agent_uuid)
end
if orig_uuid ~= "" and orig_uuid ~= "-" then
    api:execute("uuid_kill", orig_uuid)
end
