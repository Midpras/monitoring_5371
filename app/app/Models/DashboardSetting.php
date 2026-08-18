<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardSetting extends Model
{
    protected $fillable = ['target_date'];

    protected function casts(): array
    {
        return ['target_date' => 'date'];
    }
}
