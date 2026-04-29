-- voxra_unmute_member.lua
--
-- Invoked from the api_hangup_hook installed on the summoner's leg during an
-- announced transfer. When the summoner drops, the muted original caller (who
-- has been listening to the consultation) needs to be unmuted so they can
-- speak with the transfer target.
--
-- Args:
--   1. conf_name
--   2. member_id

local SCRIPT_NAME = "[voxra_unmute_member.lua]"

local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

local conf_name = argv[1] or ""
local member_id = argv[2] or ""

if conf_name == "" or member_id == "" then
    log("ERR", "missing args (conf_name, member_id)")
    return
end

local api = freeswitch.API()
local cmd = string.format("conference %s unmute %s", conf_name, member_id)
local result = api:executeString(cmd)
log("NOTICE", "unmute conf=" .. conf_name .. " member=" .. member_id .. " -> " .. tostring(result))
