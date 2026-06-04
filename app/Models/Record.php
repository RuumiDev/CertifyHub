<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Record extends Model
{
    protected $fillable = [
        'batch_id',
        'recipient_name',
        'identification_number',
        'group_identifier',
        'team_members',
        'override_settings',
        'generation_status',
    ];

    protected $casts = [
        'team_members'      => 'array',
        'override_settings' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
