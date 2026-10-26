<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'geocode_url' => 'https://maps.googleapis.com/maps/api/geocode/json',
        'routes_url' => 'https://routes.googleapis.com/directions/v2:computeRoutes',
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'access_token' => env('META_ACCESS_TOKEN'),
    ],

    'sms' => [
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID'),
        'otp_template_id' => env('SMS_OTP_TEMPLATE_ID'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

    'attendance_device' => [
        'api_url' => env('ATTENDANCE_DEVICE_API_URL'),
        'api_key' => env('ATTENDANCE_DEVICE_API_KEY'),
        'device_id' => env('ATTENDANCE_DEVICE_ID'),
    ],

];
