<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party API Integrations
    |--------------------------------------------------------------------------
    |
    | Central registry for external service credentials. Values saved in the
    | admin Integration Settings page are encrypted in the database. Each field
    | may optionally fall back to an environment variable when not stored.
    |
    */

    'providers' => [

        'google_maps' => [
            'label' => 'Google Maps',
            'description' => 'SFC distance calculation and travel segment routing via Google Routes and Geocoding APIs.',
            'testable' => true,
            'env_fallbacks' => [
                'api_key' => 'services.google_maps.api_key',
            ],
            'fields' => [
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'password',
                    'placeholder' => 'AIza...',
                    'helper' => 'Enable Routes API and Geocoding API in Google Cloud Console.',
                ],
            ],
        ],

        'meta' => [
            'label' => 'Meta (Facebook)',
            'description' => 'Lead ads, webhooks, and social platform integrations.',
            'testable' => false,
            'env_fallbacks' => [
                'app_id' => 'services.meta.app_id',
                'app_secret' => 'services.meta.app_secret',
                'access_token' => 'services.meta.access_token',
            ],
            'fields' => [
                'app_id' => [
                    'label' => 'App ID',
                    'type' => 'text',
                    'placeholder' => '1234567890',
                ],
                'app_secret' => [
                    'label' => 'App Secret',
                    'type' => 'password',
                ],
                'access_token' => [
                    'label' => 'Access Token',
                    'type' => 'password',
                ],
            ],
        ],

        'sms' => [
            'label' => 'SMS Gateway',
            'description' => 'Transactional SMS for OTP, alerts, and notifications.',
            'testable' => false,
            'env_fallbacks' => [
                'api_key' => 'services.sms.api_key',
                'sender_id' => 'services.sms.sender_id',
                'otp_template_id' => 'services.sms.otp_template_id',
            ],
            'fields' => [
                'provider' => [
                    'label' => 'Provider',
                    'type' => 'select',
                    'options' => [
                        'msg91' => 'MSG91',
                        'twilio' => 'Twilio',
                        'textlocal' => 'Textlocal',
                        'other' => 'Other',
                    ],
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'password',
                ],
                'sender_id' => [
                    'label' => 'Sender ID',
                    'type' => 'text',
                    'placeholder' => 'COMPNY',
                ],
                'otp_template_id' => [
                    'label' => 'MSG91 OTP Template ID',
                    'type' => 'text',
                ],
            ],
        ],

        'email' => [
            'label' => 'Email API',
            'description' => 'Transactional email delivery via third-party providers.',
            'testable' => false,
            'env_fallbacks' => [
                'api_key' => 'services.resend.key',
            ],
            'fields' => [
                'provider' => [
                    'label' => 'Provider',
                    'type' => 'select',
                    'options' => [
                        'resend' => 'Resend',
                        'mailgun' => 'Mailgun',
                        'postmark' => 'Postmark',
                        'ses' => 'Amazon SES',
                    ],
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'password',
                ],
                'domain' => [
                    'label' => 'Sending Domain',
                    'type' => 'text',
                    'placeholder' => 'mail.example.com',
                ],
            ],
        ],

        'attendance_device' => [
            'label' => 'Attendance Device',
            'description' => 'Biometric or RFID attendance hardware integration.',
            'testable' => false,
            'env_fallbacks' => [
                'api_url' => 'services.attendance_device.api_url',
                'api_key' => 'services.attendance_device.api_key',
                'device_id' => 'services.attendance_device.device_id',
            ],
            'fields' => [
                'api_url' => [
                    'label' => 'API URL',
                    'type' => 'text',
                    'placeholder' => 'https://device.example.com/api',
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'password',
                ],
                'device_id' => [
                    'label' => 'Device ID',
                    'type' => 'text',
                ],
            ],
        ],

    ],

];
