<?php
namespace App\Models;
class ProjectCostEntry extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2'];
    public function project() { return $this->belongsTo(Project::class); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
}
