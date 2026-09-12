<?php

return [
    'gemini_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
    'gemini_timeout' => (int) env('GEMINI_TIMEOUT', 30),
    'database_connection' => env('AI_DB_CONNECTION', 'ai_readonly'),
    'max_rows' => (int) env('MAX_AI_ROWS', 100),
    'max_repair_attempts' => (int) env('AI_MAX_REPAIR_ATTEMPTS', 2),
    'schema_cache_minutes' => (int) env('AI_SCHEMA_CACHE_MINUTES', 10),
    'max_message_chars' => (int) env('AI_MAX_MESSAGE_CHARS', 4000),
];
