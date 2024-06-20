<?php
$firebaseConfigPath = env('FIREBASE_CONFIG_PATH');
$firebaseConfig = json_decode(file_get_contents(base_path($firebaseConfigPath)), true);
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
    'twilio' => [
        'TWILIO_ACCOUNT_SID' => env('TWILIO_ACCOUNT_SID'),
        'TWILIO_AUTH_TOKEN' => env('TWILIO_AUTH_TOKEN'),
        'TWILIO_OTP_SERVICE_ID' => env('TWILIO_OTP_SERVICE_ID'),
    ],
    'firebase' => [
        'url' => 'app/credentials/firebase.json',
        'api_key' => $firebaseConfig['apiKey'] ?? null,
        'auth_domain' => $firebaseConfig['authDomain'] ?? null,
        'database_url' => $firebaseConfig['databaseURL'] ?? null,
        'project_id' => $firebaseConfig['projectId'] ?? null,
    ],

];
