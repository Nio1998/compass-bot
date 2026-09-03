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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        // Firma usata per verificare che le richieste ai nostri endpoint /slack/*
        // arrivino davvero da Slack (vedi VerifySlackSignature).
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ollama' => [
        'url'              => env('OLLAMA_URL', 'http://localhost:11434/api'),
        'model'            => env('OLLAMA_MODEL', 'llama3'),
        'embedding_model'  => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    ],

    'chroma' => [
        // Server ChromaDB self-hosted (vedi HasSlidesVectorStore).
        'host'       => env('CHROMA_HOST', 'http://localhost:8000'),
        'collection' => env('CHROMA_COLLECTION', 'gps_slides'),
        // Documenti di riferimento reali (progetto esempio) usati solo da
        // GpsDocumentValidator, mai da GpsQaBot — vedi CompositeVectorStore.
        'validation_collection' => env('CHROMA_VALIDATION_COLLECTION', 'gps_validation_refs'),
    ],

    // Password del mini pannello admin (upload/ingestione slide), niente sistema utenti.
    'admin_password' => env('ADMIN_PASSWORD'),

];
