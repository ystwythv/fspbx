-- Unit tests for resources/lua/push_wake.lua's no-answer handling
-- (voxragtm#45 Complete-mode failover). Runs push_wake.lua against stubbed
-- FreeSWITCH globals — no FreeSWITCH needed:
--
--   lua5.3 tests/lua/push_wake_failover_test.lua   (from the repo root)
--
-- Exits non-zero on the first failing scenario.

local SCRIPT = "resources/lua/push_wake.lua"

local function run(opts)
    local executed, logs, hungup = {}, {}, nil
    local vars = {
        destination_number = opts.ext or "200",
        domain_name = "acme.voxra.uk",
        caller_id_name = "Caller",
        caller_id_number = "+447700900123",
        uuid = "call-uuid-1",
    }
    for k, v in pairs(opts.vars or {}) do vars[k] = v end
    local ready = true
    local aor = vars.destination_number .. "@" .. vars.domain_name
    local user_data = opts.user_data or {}

    local api_responses = {
        ["global_getvar push_webhook_url"] = "http://127.0.0.1/webhook/freeswitch",
        ["global_getvar push_webhook_secret"] = "s3cret",
        ["sofia_contact */" .. aor] = opts.contacts or "error/user_not_registered",
    }

    _G.freeswitch = {
        API = function()
            return {
                executeString = function(_, cmd)
                    if api_responses[cmd] then return api_responses[cmd] end
                    local var = cmd:match("^user_data " .. aor:gsub("%p", "%%%0") .. " var (.+)$")
                    if var then return user_data[var] or "" end
                    return ""
                end,
            }
        end,
        consoleLog = function(_, msg) table.insert(logs, msg) end,
        Dbh = function() return nil end,
    }

    _G.session = {
        ready = function() return ready end,
        getVariable = function(_, k) return vars[k] end,
        setVariable = function(_, k, v) vars[k] = v end,
        preAnswer = function() end,
        answer = function() vars.call_answered = "true" end,
        sleep = function() end,
        hangup = function(_, cause) hungup = cause or "NORMAL_CLEARING"; ready = false end,
        execute = function(_, app, data)
            table.insert(executed, { app = app, data = data })
            if app == "bridge" then
                if opts.bridge_answers then
                    ready = false
                else
                    vars.originate_disposition = opts.bridge_cause or "NO_ANSWER"
                end
            elseif app == "transfer" then
                ready = false
            end
        end,
    }

    package.loaded["resources.functions.lunajson"] = { encode = function() return "{}" end }
    package.loaded["resources.functions.database"] = {
        new = function()
            return {
                connected = function() return true end,
                first_value = function() return opts.voicemail_enabled or "true" end,
                release = function() end,
            }
        end,
    }

    dofile(SCRIPT)
    return { executed = executed, logs = logs, hungup = hungup }
end

local function find(res, app)
    for _, e in ipairs(res.executed) do
        if e.app == app then return e end
    end
    return nil
end

local failures, passed = 0, 0
local function check(name, cond, res)
    if cond then
        passed = passed + 1
        print("ok   - " .. name)
    else
        failures = failures + 1
        print("FAIL - " .. name)
        for _, e in ipairs(res.executed) do print("       exec " .. e.app .. " " .. tostring(e.data)) end
        for _, l in ipairs(res.logs) do io.write("       log  " .. l) end
    end
end

-- Voxra Complete mobile extension: all three forwards → reception agent 9250.
local complete_fwd = {
    ring_target = "fmc",
    forward_no_answer_enabled = "true", forward_no_answer_destination = "9250",
    forward_busy_enabled = "true", forward_busy_destination = "9251",
    forward_user_not_registered_enabled = "true", forward_user_not_registered_destination = "9252",
}
local fmc_contact = "sofia/internal/sip:200@10.0.0.9:5060;device=fmc"

local r

r = run({ user_data = complete_fwd })
check("fmc, eSIM not registered → forward_user_not_registered, no ring, no voicemail",
    find(r, "transfer") and find(r, "transfer").data == "9252 XML acme.voxra.uk"
        and not find(r, "bridge") and not find(r, "voicemail"), r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_cause = "NO_ANSWER" })
check("fmc, registered, no answer → forward_no_answer",
    find(r, "bridge") and find(r, "transfer") and find(r, "transfer").data == "9250 XML acme.voxra.uk"
        and not find(r, "voicemail"), r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_cause = "USER_BUSY" })
check("fmc, busy → forward_busy",
    find(r, "transfer") and find(r, "transfer").data == "9251 XML acme.voxra.uk", r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_cause = "CALL_REJECTED" })
check("fmc, rejected → forward_busy",
    find(r, "transfer") and find(r, "transfer").data == "9251 XML acme.voxra.uk", r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_cause = "NORMAL_TEMPORARY_FAILURE" })
check("fmc, phone off / out of signal (503) → forward_no_answer",
    find(r, "transfer") and find(r, "transfer").data == "9250 XML acme.voxra.uk", r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_cause = "SUBSCRIBER_ABSENT" })
check("fmc, subscriber absent → forward_user_not_registered",
    find(r, "transfer") and find(r, "transfer").data == "9252 XML acme.voxra.uk", r)

r = run({ user_data = complete_fwd, contacts = fmc_contact })
local cof
for _, e in ipairs(r.executed) do
    if e.app == "set" and tostring(e.data):match("^continue_on_fail=") then cof = e.data end
end
check("bridge keeps the caller on every failure (continue_on_fail=true)", cof == "continue_on_fail=true", r)

r = run({ user_data = complete_fwd, contacts = fmc_contact, bridge_answers = true })
check("fmc, answered → no failover", find(r, "bridge") and not find(r, "transfer") and not find(r, "voicemail"), r)

-- Channel vars (set by the user_exists dialplan) win over user_data.
r = run({ user_data = complete_fwd, vars = { forward_user_not_registered_enabled = "true", forward_user_not_registered_destination = "9299" } })
check("channel forward vars take precedence",
    find(r, "transfer") and find(r, "transfer").data == "9299 XML acme.voxra.uk", r)

-- No agent (forwards off): voicemail remains the fallback.
r = run({ user_data = { ring_target = "fmc", forward_user_not_registered_enabled = "false" } })
check("fmc, no forward configured → voicemail",
    not find(r, "transfer") and find(r, "voicemail") and find(r, "voicemail").data == "default acme.voxra.uk 200", r)

-- Voicemail box disabled and no forward → hang up rather than VM prompt.
r = run({ user_data = { ring_target = "fmc" }, voicemail_enabled = "false" })
check("fmc, no forward, voicemail disabled → hangup NO_ANSWER",
    not find(r, "voicemail") and r.hungup == "NO_ANSWER", r)

-- A forward back to the same extension must not loop.
r = run({ user_data = { ring_target = "fmc", forward_user_not_registered_enabled = "true", forward_user_not_registered_destination = "200" } })
check("forward to self is ignored (voicemail, no loop)", not find(r, "transfer") and find(r, "voicemail"), r)

-- Voxra Line (ring_target=both, no push token): push_wake stays a no-op so
-- local_extension's follow-me → voicemail behaviour is unchanged.
r = run({ ext = "9260", user_data = { ring_target = "both" } })
check("Line / legacy ring_target=both without push token → untouched",
    #r.executed == 0 and r.hungup == nil, r)

print(string.format("\n%d passed, %d failed", passed, failures))
os.exit(failures == 0 and 0 or 1)
