<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIQueryAudit extends Model
{
    protected $table = 'ai_query_audits';
    protected $guarded = [];
    protected $casts = ['parameters' => 'array', 'validation_passed' => 'boolean'];
}
