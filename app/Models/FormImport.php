<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormImport extends Model
{
    protected $fillable = [
        'form_id',
        'source_type',
        'file_path',
        'status',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'summary' => 'array',
        'metadata' => 'array',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
