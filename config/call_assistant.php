<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business
    |--------------------------------------------------------------------------
    |
    | The business the AI agent answers calls for, and the timezone used to
    | understand phrases like "tomorrow at 10" and to book calendar events.
    |
    */

    'business_name' => env('CALL_ASSISTANT_BUSINESS_NAME', 'Bright Smile Dental'),

    /*
    |--------------------------------------------------------------------------
    | Demo account
    |--------------------------------------------------------------------------
    |
    | Created by the seeder and offered on the sign-in page, so anyone can
    | try the app in one click. Set CALL_ASSISTANT_DEMO_LOGIN=false to hide
    | it on a real deployment.
    |
    */

    'demo_account' => env('CALL_ASSISTANT_DEMO_LOGIN', true) ? [
        'email' => 'demo@callassistant.test',
        'password' => 'demo-password',
    ] : null,

    'timezone' => env('CALL_ASSISTANT_TIMEZONE', 'UTC'),

    'default_duration_minutes' => (int) env('CALL_ASSISTANT_DEFAULT_DURATION', 30),

    /*
    |--------------------------------------------------------------------------
    | Incoming webhook
    |--------------------------------------------------------------------------
    |
    | Every request to /api/webhooks/calls must carry an X-Signature header:
    | "sha256=" followed by the HMAC-SHA256 of the raw body using this secret.
    |
    */

    'webhook_secret' => env('CALL_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    |
    | Leave the key empty to run in demo mode, where simple built-in rules
    | stand in for the AI so the project can be tried without any accounts.
    |
    */

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Calendar
    |--------------------------------------------------------------------------
    |
    | A Google Cloud service account JSON key, and the ID of a calendar that
    | has been shared with the service account's email ("Make changes to
    | events"). Leave either empty and bookings are recorded in demo mode.
    |
    */

    'google_calendar' => [
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'credentials' => env('GOOGLE_CALENDAR_CREDENTIALS', 'storage/app/private/google-service-account.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound calls
    |--------------------------------------------------------------------------
    |
    | Calls placed through Vapi (https://vapi.ai). Leave the key empty to run
    | outbound calls in demo mode, where a sample conversation comes back a
    | few seconds after dialing.
    |
    | Only contacts who agreed to be called are ever dialed, never outside
    | calling hours (in the business timezone), and never after they asked
    | not to be called again.
    |
    */

    'outbound' => [
        'vapi_key' => env('VAPI_API_KEY'),
        'vapi_assistant_id' => env('VAPI_ASSISTANT_ID'),
        'vapi_phone_number_id' => env('VAPI_PHONE_NUMBER_ID'),
        'vapi_base_url' => rtrim(env('VAPI_BASE_URL', 'https://api.vapi.ai'), '/'),

        'calling_hours' => [
            'start' => env('OUTBOUND_CALLING_HOURS_START', '08:00'),
            'end' => env('OUTBOUND_CALLING_HOURS_END', '21:00'),
        ],

        // Numbers in a CSV without a leading + get this country code, e.g. "1" or "962".
        'default_country_code' => env('OUTBOUND_DEFAULT_COUNTRY_CODE'),

        'max_batch' => (int) env('OUTBOUND_MAX_BATCH', 25),
        'seconds_between_calls' => (int) env('OUTBOUND_SECONDS_BETWEEN_CALLS', 20),

        'purposes' => [
            'Appointment reminder',
            'Book a routine check-up',
            'Follow up after a recent visit',
            'Reschedule a missed appointment',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sample calls
    |--------------------------------------------------------------------------
    |
    | Used by the "Simulate a call" button, `php artisan calls:simulate`
    | and the database seeder.
    |
    */

    'samples' => [
        [
            'caller' => ['name' => 'Sarah Mitchell', 'phone' => '+1 415 555 0142', 'email' => 'sarah.mitchell@example.com'],
            'duration_seconds' => 146,
            'transcript' => [
                ['role' => 'agent', 'text' => 'Thanks for calling Bright Smile Dental, this is Ava. How can I help you today?'],
                ['role' => 'caller', 'text' => 'Hi, I would like to book an appointment for a cleaning. Do you have anything tomorrow morning?'],
                ['role' => 'agent', 'text' => 'We do. Would 10 AM tomorrow work for you?'],
                ['role' => 'caller', 'text' => 'Yes, 10 AM is perfect. Please book it under Sarah Mitchell.'],
                ['role' => 'agent', 'text' => 'Done. You will get a confirmation shortly. Anything else?'],
                ['role' => 'caller', 'text' => 'No, that is all. Thanks so much!'],
            ],
        ],
        [
            'caller' => ['name' => 'David Chen', 'phone' => '+1 646 555 0187', 'email' => null],
            'duration_seconds' => 212,
            'transcript' => [
                ['role' => 'agent', 'text' => 'Bright Smile Dental, this is Ava speaking.'],
                ['role' => 'caller', 'text' => 'Hi. I was charged twice for my visit last week and I am honestly pretty frustrated.'],
                ['role' => 'agent', 'text' => 'I am sorry about that. Let me note it for our billing team so they can look into it today.'],
                ['role' => 'caller', 'text' => 'Please do. I need someone to call me back about the refund.'],
                ['role' => 'agent', 'text' => 'Of course. Someone from billing will call you back at this number.'],
            ],
        ],
        [
            'caller' => ['name' => 'Lina Haddad', 'phone' => '+44 20 7946 0321', 'email' => 'lina.haddad@example.com'],
            'duration_seconds' => 98,
            'transcript' => [
                ['role' => 'agent', 'text' => 'Thank you for calling Bright Smile Dental. How can I help?'],
                ['role' => 'caller', 'text' => 'Do you accept the BlueShield insurance plan, and are you open on Saturdays?'],
                ['role' => 'agent', 'text' => 'We accept BlueShield, and we are open on Saturdays from 9 to 2.'],
                ['role' => 'caller', 'text' => 'Great, thanks for the help.'],
            ],
        ],
    ],

];
