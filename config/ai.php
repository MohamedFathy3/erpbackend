<?php

return [
    'gemini_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
    // Do not silently add fallback models: every extra model can add another
    // provider round-trip. Remove the primary model if it is repeated here.
    'gemini_fallback_models' => array_values(array_filter(
        array_unique(array_map('trim', explode(',', env('GEMINI_FALLBACK_MODELS', '')))),
        static fn (string $model): bool => $model !== '' && $model !== env('GEMINI_MODEL', 'gemini-3.7-flash')
    )),
    'gemini_timeout' => (int) env('GEMINI_TIMEOUT', 5),
    'gemini_connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 3),
    'gemini_retries' => (int) env('GEMINI_RETRIES', 0),
    'gemini_max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 4096),
    'expose_errors' => (bool) env('AI_EXPOSE_ERRORS', true),
    'database_connection' => env('AI_DB_CONNECTION') ?: 'ai_readonly',
    'max_rows' => (int) env('MAX_AI_ROWS', 100),
    'max_repair_attempts' => (int) env('AI_MAX_REPAIR_ATTEMPTS', 1),
    'schema_cache_minutes' => (int) env('AI_SCHEMA_CACHE_MINUTES', 60),
    'max_message_chars' => (int) env('AI_MAX_MESSAGE_CHARS', 4000),
];
