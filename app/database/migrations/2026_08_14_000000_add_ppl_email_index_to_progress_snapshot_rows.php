<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_snapshot_rows', function (Blueprint $table) {
            $table->index(['upload_id', 'ppl_email']);
        });
    }

    public function down(): void
    {
        Schema::table('progress_snapshot_rows', function (Blueprint $table) {
            $table->dropIndex('progress_snapshot_rows_upload_id_ppl_email_index');
        });
    }
};
