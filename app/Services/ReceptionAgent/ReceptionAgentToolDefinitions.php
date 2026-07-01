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
     * @return array<int, array{name:string, description:string, properties:array<string,mixed>, required:array<int,string>}>
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
                'name' => 'capture_lead',
                'description' => 'Record who is calling and what they need — use this as you qualify a new caller. Capture their name, the job/enquiry, their postcode and how urgent it is. Safe to call more than once as you learn more; it updates the same lead. If the caller has rung before, the result tells you (returning_caller) and what they last wanted, so you can greet them accordingly.',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => "Caller's name"],
                    'caller_number' => ['type' => 'string', 'description' => "Caller's phone number (ask if not already known)"],
                    'postcode' => ['type' => 'string', 'description' => 'Job/site postcode or area'],
                    'job_description' => ['type' => 'string', 'description' => 'What the caller needs, in a sentence'],
                    'urgency' => ['type' => 'string', 'enum' => ['emergency', 'urgent', 'routine'], 'description' => 'How urgent the job is'],
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
                'description' => 'Look up what we already know about the current caller (or a given number) — their name, how many times they have called or booked, and any notes on file — so you can greet returning customers by context. Call this early for a caller you may have dealt with before.',
                'properties' => [
                    'number' => ['type' => 'string', 'description' => "The caller's number (optional; defaults to this caller)"],
                ],
                'required' => [],
            ],
            [
                'name' => 'remember_about_caller',
                'description' => 'Save a note about this caller to their record so you and the whole team remember it next time (e.g. "prefers morning appointments", "gate code 1234", "cash only"). Shared across the business.',
                'properties' => [
                    'note' => ['type' => 'string', 'description' => 'The note to remember about the caller'],
                    'number' => ['type' => 'string', 'description' => "The caller's number (optional; defaults to this caller)"],
                ],
                'required' => ['note'],
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
}
