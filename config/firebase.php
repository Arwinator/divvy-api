<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Credentials
    |--------------------------------------------------------------------------
    |
    | Path to your Firebase service account credentials JSON file.
    | You can download this from your Firebase Console:
    | Project Settings > Service Accounts > Generate New Private Key
    |
    */

    'credentials' => env('FIREBASE_CREDENTIALS'),

    /*
    |--------------------------------------------------------------------------
    | Firebase Database URL
    |--------------------------------------------------------------------------
    |
    | Your Firebase Realtime Database URL (optional, only if using Realtime Database)
    |
    */

    'database_url' => env('FIREBASE_DATABASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Firebase Storage Bucket
    |--------------------------------------------------------------------------
    |
    | Your Firebase Storage bucket name (optional, only if using Cloud Storage)
    |
    */

    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),

];
