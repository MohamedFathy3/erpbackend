<?php

return [
    'gemini_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
    // Keep current fallbacks available even when an old deployment still has
    // GEMINI_FALLBACK_MODELS=gemini-2.5-flash in its .env file.
    'gemini_fallback_models' => array_values(array_unique(array_merge(
        array_values(array_filter(array_map('trim', explode(',', env('GEMINI_FALLBACK_MODELS', ''))))),
        ['gemini-3.7-flash', 'gemini-3.5-flash-lite']
    ))),
    'gemini_timeout' => (int) env('GEMINI_TIMEOUT', 30),
    'expose_errors' => (bool) env('AI_EXPOSE_ERRORS', true),
    'database_connection' => env('AI_DB_CONNECTION', 'ai_readonly'),
    'max_rows' => (int) env('MAX_AI_ROWS', 100),
    'max_repair_attempts' => (int) env('AI_MAX_REPAIR_ATTEMPTS', 2),
    'schema_cache_minutes' => (int) env('AI_SCHEMA_CACHE_MINUTES', 10),
    'max_message_chars' => (int) env('AI_MAX_MESSAGE_CHARS', 4000),
];
