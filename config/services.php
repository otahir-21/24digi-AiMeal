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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | NestJS Backend Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for integrating with the main NestJS backend API
    | for delivery scheduling, user management, and order processing.
    |
    */
    'nestjs' => [
        'base_url' => env('NESTJS_API_BASE_URL', 'https://api.24digi.ae'),
        'api_key' => env('NESTJS_API_KEY'),
        'webhook_secret' => env('NESTJS_WEBHOOK_SECRET'),
        'timeout' => env('NESTJS_API_TIMEOUT', 30),
        'retry_attempts' => env('NESTJS_API_RETRY_ATTEMPTS', 3),
        
        // Specific endpoint paths
        'endpoints' => [
            'schedule_delivery' => '/api/ai-meal-delivery/schedule',
            'delivery_status' => '/api/ai-meal-delivery/status',
            'consumption_update' => '/api/ai-meal-delivery/consumption',
            'user_deliveries' => '/api/ai-meal-delivery/user/{profileId}',
        ],
    ],

];
