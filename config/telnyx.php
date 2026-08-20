<?php

return [
    'api_key' => env('TELNYX_API_KEY'),
    'message_base_url' => env('TELNYX_BASE_URL', 'https://api.telnyx.com/v2'),

    // Webhook auth for the reception-agent tool/dynamic-variables endpoints
    // (VerifyTelnyxSignature middleware). public_key is the account's Ed25519
    // public key (Mission Control → Keys & Credentials → Public Key), also
    // used by config/webhook-client.php for messaging webhooks.
    'public_key' => env('TELNYX_PUBLIC_KEY'),
    'webhook_tolerance' => (int) env('TELNYX_WEBHOOK_TOLERANCE', 300),

    // Shared secret set as a header/query param on the assistant's webhook
    // tool definitions (TelnyxConvaiService::syncReceptionAgentTools). Either
    // this or a valid Ed25519 signature authenticates a request.
    'tool_secret' => env('VOXRA_TELNYX_TOOL_SECRET', ''),

    // Dev/staging escape hatch: accept unauthenticated webhooks. Never in prod.
    'webhook_allow_unsigned' => (bool) env('TELNYX_WEBHOOK_ALLOW_UNSIGNED', false),
];
