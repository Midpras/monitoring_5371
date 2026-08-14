<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressSnapshotRow extends Model
{
    protected $fillable = [
        'upload_id', 'assignment_key', 'row_fingerprint', 'row_number', 'kode_subsls', 'nama_sls',
        'ppl_id', 'ppl_name', 'ppl_email', 'pml_name', 'pml_email', 'capaian_ppl', 'capaian_pml',
        'target', 'status_produktivitas', 'status_ppl_sobat', 'status_pml_sobat', 'kategori_mitra',
        'assignment_url', 'jenis_mitra',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ProgressUpload::class, 'upload_id');
    }
}
