-- voxra_owner_confirm.lua
--
-- Ring-first "press 1 to accept" for the owner's mobile (voxragtm#23, #141),
-- asked ONCE. Runs as group_confirm_key=exec / group_confirm_file on the
-- ring-first bridge (ProvisionNumberService::ringFirstBridgeActions).
--
-- The bridge goes through loopback/<mobile>/<domain> so the domain's outbound
-- routing picks the gateway, and mod_loopback copies the dial-string
-- variables to the loopback's b-leg. With plain group_confirm_key=1 the
-- confirm therefore ran twice: once on the real mobile leg (the owner pressed
-- 1) and again on loopback-a, which played the prompt a second time and
-- waited for another 1 — an owner who pressed once was dropped to the AI or
-- voicemail (QA run 80205126, 30 Sept 2026).
--
-- Here the loopback leg accepts straight away (its b-leg has already been
-- confirmed on the real phone), and the real phone leg gets the prompt:
-- 1 accepts; anything else, or nothing after three tries, hangs the leg up so
-- a carrier voicemail can't swallow the call (the caller then goes to the
-- agent / voicemail as before).

local SCRIPT_NAME = "[voxra_owner_confirm.lua]"
local function log(level, msg)
    freeswitch.consoleLog(level, SCRIPT_NAME .. " " .. tostring(msg) .. "\n")
end

if not session or not session:ready() then
    return
end

local name = session:getVariable("channel_name") or ""
if name:find("^loopback/") then
    log("DEBUG", "loopback leg " .. name .. ": accepted (confirmed on the phone leg)")
    return
end

local sounds_dir = session:getVariable("sounds_dir") or "/usr/share/freeswitch/sounds"
local lang = session:getVariable("default_language") or "en"
local dialect = session:getVariable("default_dialect") or "us"
local voice = session:getVariable("default_voice") or "callie"
local prompt = sounds_dir .. "/" .. lang .. "/" .. dialect .. "/" .. voice .. "/ivr/ivr-accept_reject_voicemail.wav"

local digit = session:playAndGetDigits(1, 1, 3, 5000, "#", prompt, "", "\\d")
if digit == "1" then
    log("NOTICE", name .. ": owner accepted")
    return
end

log("NOTICE", name .. ": not accepted (digit '" .. tostring(digit) .. "')")
session:hangup(digit == "2" and "CALL_REJECTED" or "NO_ANSWER")
