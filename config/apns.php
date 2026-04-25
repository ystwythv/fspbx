<?php

return [
    'key_id' => env('APNS_KEY_ID', 'ZC66ADWFF5'),
    'team_id' => env('APNS_TEAM_ID', 'WU7W6PB5M8'),
    'bundle_id' => env('APNS_BUNDLE_ID', 'com.iqmobile.IQCRMApp'),
    // VoIP topic is bundle_id + ".voip"; alert_topic is the regular bundle id.
    // Both are served by the same .p8 key (key_id), provided the key is enabled
    // for both topics in App Store Connect.
    'alert_topic' => env('APNS_ALERT_TOPIC', 'com.iqmobile.IQCRMApp'),
    'key_path' => env('APNS_KEY_PATH', storage_path('app/apns/AuthKey_ZC66ADWFF5.p8')),
    'production' => env('APNS_PRODUCTION', false),
];
