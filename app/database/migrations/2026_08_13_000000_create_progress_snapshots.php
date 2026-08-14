<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('password');
        });

        Schema::create('progress_uploads', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedInteger('version')->nullable();
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->string('file_checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('validating');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('validation_error_count')->default(0);
            $table->json('validation_errors')->nullable();
            $table->json('validation_warnings')->nullable();
            $table->json('validation_preview')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_date', 'version']);
            $table->index(['snapshot_date', 'status']);
            $table->index('file_checksum');
        });

        DB::statement("CREATE UNIQUE INDEX progress_uploads_one_active_per_date ON progress_uploads (snapshot_date) WHERE status = 'imported' AND superseded_at IS NULL");

        Schema::create('progress_snapshot_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('progress_uploads')->cascadeOnDelete();
            $table->string('assignment_key');
            $table->string('row_fingerprint', 64);
            $table->unsignedInteger('row_number');
            $table->string('kode_subsls');
            $table->string('nama_sls')->nullable();
            $table->string('ppl_id');
            $table->string('ppl_name')->nullable();
            $table->string('ppl_email')->nullable();
            $table->string('pml_name')->nullable();
            $table->string('pml_email')->nullable();
            $table->unsignedInteger('capaian_ppl')->nullable();
            $table->unsignedInteger('capaian_pml')->nullable();
            $table->unsignedInteger('target')->nullable();
            $table->string('status_produktivitas')->nullable();
            $table->string('status_ppl_sobat')->nullable();
            $table->string('status_pml_sobat')->nullable();
            $table->string('kategori_mitra')->nullable();
            $table->text('assignment_url')->nullable();
            $table->string('jenis_mitra')->nullable();
            $table->timestamps();

            $table->unique(['upload_id', 'assignment_key']);
            $table->index(['upload_id', 'ppl_id']);
            $table->index(['upload_id', 'pml_email']);
            $table->index(['upload_id', 'kode_subsls']);
            $table->index(['upload_id', 'status_produktivitas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_snapshot_rows');
        Schema::dropIfExists('progress_uploads');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
