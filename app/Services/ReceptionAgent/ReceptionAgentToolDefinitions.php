<?php

namespace App\Services\ReceptionAgent;

/**
 * Provider-neutral definitions of the reception-agent tools.
 *
 * Each provider (ElevenLabs, Telnyx) renders these into its own tool schema.
 * The dispatch endpoint is a single webhook; the `tool_name` (enum) in the body
 * selects the method, and `conversation_id` ties the call to its Redis session.
 *
 * `properties` are JSON-Schema property maps; `required` lists required arg
 * names (tool_name + conversation_id are always added by the renderers).
 */
class ReceptionAgentToolDefinitions
{
    /**
     * @param array<string,bool> $enabled per-tool on/off map (agent->tools_enabled)
     * Optional keys (Telnyx only): store_as_variables (dynamic variable =>
     * response path), filler (spoken if the webhook is slow), timeout_ms.
     *
     * @return array<int, array{name:string, description:string, properties:array<string,mixed>, required:array<int,string>, store_as_variables?:array<string,string>, filler?:string, timeout_ms?:int}>
     */
    public static function list(array $enabled): array
    {
        $all = [
            [
                'name' => 'lookup_user',
                'description' => 'Find a colleague by name in the directory. Returns each match with extension, full name, and live availability: status is "available" (free to take a call), "busy" (currently on a call), "offline" (phone off/unreachable), or "unknown". Use this to answer whether someone is available before transferring or adding them.',
                'properties' => ['query' => ['type' => 'string', 'description' => 'Person name or extension to search for']],
                'required' => ['query'],
            ],
            [
                'name' => 'transfer_call',
                'description' => 'Blind-transfer the held call to an extension and exit.',
                'properties' => ['extension' => ['type' => 'string', 'description' => 'Target extension number']],
                'required' => ['extension'],
            ],
            [
                'name' => 'announced_transfer',
                'description' => 'Announced (warm) transfer: ring the target, introduce the call, then drop yourself so the original caller is connected.',
                'properties' => ['extension' => ['type' => 'string', 'description' => 'Target extension number']],
                'required' => ['extension'],
            ],
            [
                'name' => 'park_call',
                'description' => 'Park the held call to a parking slot and read back the slot number.',
                'properties' => [],
                'required' => [],
            ],
            [
                'name' => 'bring_back',
                'description' => 'Retrieve a previously parked call by slot number.',
                'properties' => ['slot' => ['type' => 'string', 'description' => 'Park slot number to retrieve']],
                'required' => ['slot'],
            ],
            [
                'name' => 'three_way_add',
                'description' => 'Add another extension to the current call as a three-way participant.',
                'properties' => ['extension' => ['type' => 'string', 'description' => 'Extension to add to the call']],
                'required' => ['extension'],
            ],
            [
                // voxragtm#122 (bloom.emergency): urgent calls must reach the
                // owner even if a transfer then fails. voxraweb records the
                // urgent lead + WhatsApp/SMS/push-alerts the owner, and only
                // then returns transfer_to, which Telnyx stores as
                // {{owner_transfer_to}} — the transfer tool's only target. So
                // the order (details → alert → transfer) is enforced in code.
                'name' => 'alert_owner',
                'description' => 'Urgent call, or the caller needs the owner now: alert the owner straight away (WhatsApp/SMS + app push) with the caller\'s name, call-back number and the problem. Call this BEFORE any transfer — the transfer only works after this succeeds. Needs the specific problem and the caller\'s name — but if they won\'t give a name, set caller_declined_name true instead of asking again. The call-back number defaults to the number they are calling from (confirm it with them). Returns transfer_available: only then may you offer to put them through.',
                'properties' => [
                    'caller_name' => ['type' => 'string', 'description' => "Caller's name (leave out if they won't give it)"],
                    'caller_declined_name' => ['type' => 'boolean', 'description' => 'true when the caller refuses to give a name — the owner is alerted with their number and the problem'],
                    'callback_number' => ['type' => 'string', 'description' => 'Number the owner should call back on — the number they are calling from unless they give another'],
                    'problem' => ['type' => 'string', 'description' => 'What has happened / what they need, in a sentence'],
                    'urgency' => ['type' => 'string', 'enum' => ['emergency', 'urgent'], 'description' => 'emergency = risk to health/safety or damage happening now; otherwise urgent'],
                ],
                // Name is not required (voxragtm#84 QA bloom.abuse): a caller who
                // refuses it must still get the owner alerted, not a loop.
                'required' => ['callback_number', 'problem'],
                // Telnyx store_fields_as_variables: response field → dynamic variable.
                'store_as_variables' => ['owner_transfer_to' => 'transfer_to'],
                'filler' => 'Bear with me a moment while I alert the owner.',
                // WhatsApp attempt + SMS fallback can exceed the 5s default.
                'timeout_ms' => 10000,
            ],
            [
                'name' => 'capture_lead',
                'description' => 'Record who is calling and what they need — use this as you qualify a new caller. Capture their name, the job/enquiry, their postcode and how urgent it is. Safe to call more than once as you learn more; it updates the same lead. If the number has rung before, the result says so (returning_caller); what they last wanted is only included once the name the caller gave matches the name on file — the number may be a shared phone.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => "Caller's name"],
                    'caller_number' => ['type' => 'string', 'description' => "Caller's phone number (ask if not already known)"],
                    'postcode' => ['type' => 'string', 'description' => 'Job/site postcode or area'],
                    'job_description' => ['type' => 'string', 'description' => 'What the caller needs, in a sentence'],
                    'urgency' => ['type' => 'string', 'enum' => ['emergency', 'urgent', 'routine'], 'description' => 'How urgent the job is. urgent/emergency (per the business\'s urgent definition) alerts the owner immediately — for those use alert_owner instead.'],
                ],
                'required' => ['job_description'],
            ],
            [
                'name' => 'check_availability',
                'description' => 'Check which appointment slots are free on a given day before offering times to the caller. Returns available slots within business hours.',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'The day to check, e.g. "2026-07-03", "tomorrow", or "next Tuesday"'],
                ],
                'required' => ['date'],
            ],
            [
                'name' => 'book_appointment',
                'description' => 'Book the job into the diary once the caller has agreed a time. Confirms the booking and returns a reference. Only book a time you have confirmed is free with check_availability.',
                'properties' => [
                    'starts_at' => ['type' => 'string', 'description' => 'Start date & time, e.g. "2026-07-03 09:30" or ISO 8601'],
                    'service' => ['type' => 'string', 'description' => 'What is being booked (e.g. "boiler repair", "cut & colour")'],
                    'duration_minutes' => ['type' => 'integer', 'description' => 'Expected duration in minutes (default 60)'],
                    'customer_name' => ['type' => 'string', 'description' => "Customer's name (defaults to the captured lead)"],
                    'customer_number' => ['type' => 'string', 'description' => "Customer's phone number (defaults to the captured lead)"],
                    'deposit_amount' => ['type' => 'number', 'description' => 'Holding deposit taken, if any'],
                ],
                'required' => ['starts_at', 'service'],
            ],
            [
                'name' => 'recall_caller',
                'description' => 'Look up what we know about the caller\'s number. The number may be a shared phone, so without confirmed_name this only returns the name on file (to ask "Am I speaking with <name>?") — never use that name or mention earlier calls until the caller confirms. Once they confirm, call again with confirmed_name to get their history (how many times they called or booked, notes, recent calls).',
                'properties' => [
                    'number' => ['type' => 'string', 'description' => "The caller's number (optional; defaults to this caller)"],
                    'confirmed_name' => ['type' => 'string', 'description' => 'The name the caller has confirmed or told you is theirs. Leave out until they have.'],
                ],
                'required' => [],
            ],
            [
                'name' => 'remember_about_caller',
                'description' => 'Save a note about this caller to their record so you and the whole team remember it next time (e.g. "prefers morning appointments", "gate code 1234", "cash only").',
                'properties' => [
                    'note' => ['type' => 'string', 'description' => 'The note to remember about the caller'],
                    'number' => ['type' => 'string', 'description' => "The caller's number (optional; defaults to this caller)"],
                ],
                'required' => ['note'],
            ],
            [
                'name' => 'remember',
                'description' => 'Remember a durable fact or preference about how THIS BUSINESS operates (e.g. "we now charge £70 call-out", "don\'t book jobs on Sundays"). For notes about a specific caller use remember_about_caller.',
                'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'The business fact/preference to remember'],
                    'category' => ['type' => 'string', 'description' => 'Optional category, e.g. pricing, policy, scheduling, general'],
                ],
                'required' => ['fact'],
            ],
            [
                'name' => 'recall_business',
                'description' => 'Recall what the owner has told you about how the business operates (pricing, policies, preferences), so you can answer accurately. Optionally filter by category.',
                'properties' => [
                    'category' => ['type' => 'string', 'description' => 'Optional category to filter by'],
                ],
                'required' => [],
            ],
            [
                // voxragtm#84 (QA bloom.abuse): "warn once, then end it" counted
                // in code — voxraweb returns the warning the first time and, on
                // the next call, logs the call as abuse (no AI minutes, no
                // owner follow-ups), keeps any genuine request as a message and
                // has the PBX hang up a few seconds later.
                'name' => 'report_abuse',
                'description' => 'Call this FIRST, before replying, every time the caller insults, swears at or threatens YOU (not frustration about their own problem). Then say exactly the `say` text it returns, word for word — never warn the caller in your own words. If it returns action end_call: say that goodbye and call the hangup tool immediately; the phone system disconnects the call a few seconds later regardless. Pass genuine_need if they have a real request, so the owner can call them back.',
                'properties' => [
                    'genuine_need' => ['type' => 'string', 'description' => 'Their real request in a few words, if they have one (e.g. "refund for yesterday\'s cut"); omit if none'],
                ],
                'required' => [],
            ],
            [
                'name' => 'record_summary',
                'description' => 'At the end of the call, record a one or two sentence summary of what happened (and the outcome) to the customer\'s timeline.',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'What happened on this call, briefly'],
                    'outcome' => ['type' => 'string', 'description' => 'Optional: booked | message | transferred | spam | abuse | no_action. spam/abuse ends the call automatically a few seconds later.'],
                ],
                'required' => ['summary'],
            ],
            [
                'name' => 'lookup_business_info',
                'description' => 'Check the business\'s own information BEFORE stating any price, coverage area, opening hours or policy. Returns the facts to answer from — state them exactly and add nothing they don\'t say (no implied charges or standard rules) — or grounded=false with a fixed script: then do NOT answer from general knowledge — say the `say` line, offer the transfer if offer_transfer is true, otherwise take a message.',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'What the caller asked, in their words'],
                    'topic' => ['type' => 'string', 'enum' => ['price', 'coverage', 'hours', 'policy', 'general'], 'description' => 'What kind of fact is needed'],
                ],
                'required' => ['question', 'topic'],
            ],
            [
                'name' => 'search_memory',
                'description' => 'Search everything you know about this business and its customers for anything relevant to a question — past jobs, quotes, preferences, policies. Use it when the structured context does not obviously cover what the caller is asking.',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to look up, in a few words'],
                ],
                'required' => ['query'],
            ],
            [
                'name' => 'send_payment_link',
                'description' => 'Text the caller a secure card-payment link for a deposit or upfront payment (their business Stripe). Confirm the amount out loud first. Only use when the business takes deposits and the caller agrees.',
                'properties' => [
                    // Bounds mirror voxraweb's server-side £1–£5000 validation.
                    'amount_pounds' => ['type' => 'number', 'description' => 'Amount in pounds, e.g. 40 for £40', 'minimum' => 1, 'maximum' => 5000],
                    'description' => ['type' => 'string', 'description' => 'What the payment is for, shown on the payment page'],
                ],
                'required' => ['amount_pounds', 'description'],
            ],
            [
                'name' => 'take_notes',
                'description' => 'Record a note from the call; notes are kept and included in the post-call summary.',
                'properties' => ['note' => ['type' => 'string', 'description' => 'The note text to record']],
                'required' => ['note'],
            ],
            [
                'name' => 'email_reminder',
                'description' => 'Email a reminder or summary to an address the caller gives you. Ask for the email address if you do not have it.',
                'properties' => [
                    'to' => ['type' => 'string', 'description' => 'Recipient email address'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject line'],
                    'body' => ['type' => 'string', 'description' => 'Email body text'],
                ],
                'required' => ['to', 'body'],
            ],
            [
                'name' => 'complete_and_exit',
                'description' => 'Call this once you have completed the user\'s request to leave the call cleanly.',
                'properties' => ['message' => ['type' => 'string', 'description' => 'Optional final spoken message before exiting']],
                'required' => [],
            ],
            [
                'name' => 'get_time_in_city',
                'description' => 'Get the current local time in a named city.',
                'properties' => ['city' => ['type' => 'string', 'description' => 'City name (e.g. New York, Tokyo)']],
                'required' => ['city'],
            ],
            [
                'name' => 'get_weather',
                'description' => 'Get the current weather in a named city.',
                'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
                'required' => ['city'],
            ],
        ];

        return array_values(array_filter($all, fn ($t) => $enabled[$t['name']] ?? true));
    }

    /**
     * Tools whose handlers live in voxraweb (customer data in Supabase,
     * voxragtm#88). Telnyx points these at the voxraweb BFF; the rest (telephony)
     * stay on this PBX.
     */
    public const DATA_TOOLS = [
        'alert_owner', 'capture_lead', 'check_availability', 'book_appointment',
        'recall_caller', 'remember_about_caller', 'remember', 'recall_business', 'record_summary', 'search_memory',
        'send_payment_link', 'lookup_business_info', 'report_abuse',
    ];

    public static function isDataTool(string $name): bool
    {
        return in_array($name, self::DATA_TOOLS, true);
    }
}
