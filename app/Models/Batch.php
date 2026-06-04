<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use HasUuids;

    protected $fillable = [
        'template_path',
        'export_format',
        'global_settings',
    ];

    protected $casts = [
        'global_settings' => 'array',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
