<?php
namespace App\Models;
class ProjectClaim extends BaseModel
{
    protected $guarded = ['id'];
    protected $casts = ['claim_date' => 'date', 'approved_at' => 'date', 'paid_at' => 'date', 'gross_amount' => 'decimal:2', 'advance_deduction' => 'decimal:2', 'retention_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];
    public function project() { return $this->belongsTo(Project::class); }
    public function revenueJournalEntry() { return $this->belongsTo(JournalEntry::class, 'revenue_journal_entry_id'); }
    public function collectionJournalEntry() { return $this->belongsTo(JournalEntry::class, 'collection_journal_entry_id'); }
    public function items() { return $this->hasMany(ProjectClaimItem::class, 'project_claim_id'); }
}
