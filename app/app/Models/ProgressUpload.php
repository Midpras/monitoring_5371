<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgressUpload extends Model
{
    protected $fillable = [
        'snapshot_date', 'version', 'original_filename', 'stored_path', 'file_checksum',
        'uploaded_by', 'status', 'row_count', 'validation_error_count', 'validation_errors',
        'validation_warnings', 'validation_preview', 'validated_at', 'imported_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'validation_errors' => 'array',
            'validation_warnings' => 'array',
            'validation_preview' => 'array',
            'validated_at' => 'datetime',
            'imported_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProgressSnapshotRow::class, 'upload_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'imported')->whereNull('superseded_at');
    }
}
