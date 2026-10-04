-- voxra_owner_bridged.lua
--
-- Runs on the caller's leg when it is bridged to the owner's phone with the
-- press-1 confirm on (push_wake.lua, bridge_pre_execute_aleg_app). The bridge
-- only starts once the owner has pressed 1, so this is the moment the owner
-- really took the call — not when the handset leg answered: iPhone Live
-- Voicemail answers at ~20s and never presses 1 (voxragtm#157 live test,
-- 4 Oct 2026: three unanswered calls were recorded as owner calls).
--
--   lua lua/voxra_owner_bridged.lua [<record_path> <record_name>]
--
-- Marks the call owner-answered (CDR, CallStatusResolver) and, when the
-- owner-call recording was armed, starts it.

if not session or not session:ready() then return end

session:execute("set", "voxra_owner_answered=true")

local path, name = argv[1], argv[2]
if path and path ~= "" and name and name ~= "" then
    session:execute("set", "record_path=" .. path)
    session:execute("set", "record_name=" .. name)
    session:execute("record_session", path .. "/" .. name)
    freeswitch.consoleLog("INFO", "[voxra_owner_bridged.lua] owner confirmed: recording " .. path .. "/" .. name .. "\n")
end
