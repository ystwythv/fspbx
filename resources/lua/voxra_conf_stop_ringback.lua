-- voxra_conf_stop_ringback.lua
--
-- Invoked via execute_on_answer on a party being dialed into the reception
-- conference (e.g. three_way_add "add Bob"). While the party is ringing we play
-- a ringback tone INTO the conference so the existing members hear it ring
-- rather than silence; the moment the party answers this stops that playback.
--
-- Args:
--   1. conference room name

local api = freeswitch.API()
local conf = argv[1] or ""
if conf ~= "" then
    api:execute("conference", conf .. " stop all")
end
