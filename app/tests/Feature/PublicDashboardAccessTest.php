<?php

namespace Tests\Feature;

use App\Models\ProgressSnapshotRow;
use App\Models\ProgressUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PublicDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_operational_api_are_public(): void
    {
        $this->activeSnapshot();

        $this->get('/')->assertOk()->assertSee('id="dashboard"', false);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('metrics.cumulative_ppl', 75);
        $this->getJson('/api/dashboard/timeseries')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/dashboard/ppl')->assertOk()->assertJsonPath('data.0.email', 'ppl@example.test');
        $this->getJson('/api/dashboard/pml')->assertOk()->assertJsonPath('data.0.email', 'pml@example.test');
        $this->getJson('/api/dashboard/breakdown?type=ppl&worker=ppl%40example.test')
            ->assertOk()
            ->assertJsonPath('data.0.kode_subsls', '5371010001000101')
            ->assertJsonPath('data.0.nama_sls', 'RT 001');
        $this->getJson('/api/dashboard/breakdown?type=pml&worker=pml%40example.test')
            ->assertOk()
            ->assertJsonPath('data.0.kode_subsls', '5371010001000101')
            ->assertJsonPath('data.0.nama_sls', 'RT 001');
        $this->getJson('/api/dashboard/filters')->assertOk()->assertJsonPath('ppl.0.label', 'ppl@example.test');
    }

    public function test_only_admins_can_access_upload_page_and_api(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->getJson('/api/admin/progress-uploads')->assertUnauthorized();
        $this->actingAs($viewer)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('id="admin-uploads"', false);
        $this->actingAs($admin)->getJson('/api/admin/progress-uploads')->assertOk();
    }

    public function test_ppl_rows_survive_when_a_worker_is_missing_from_the_current_snapshot(): void
    {
        $this->activeSnapshot();
        $previous = ProgressUpload::create([
            'snapshot_date' => '2026-08-12',
            'version' => 1,
            'original_filename' => 'previous.xlsx',
            'file_checksum' => str_repeat('c', 64),
            'status' => 'imported',
            'row_count' => 1,
            'imported_at' => now()->subDay(),
        ]);

        ProgressSnapshotRow::create([
            'upload_id' => $previous->id,
            'assignment_key' => '5371010001000102:ppl-removed',
            'row_fingerprint' => str_repeat('d', 64),
            'row_number' => 2,
            'kode_subsls' => '5371010001000102',
            'ppl_id' => 'ppl-removed',
            'ppl_email' => 'removed@example.test',
            'pml_email' => 'pml@example.test',
            'capaian_ppl' => 20,
            'capaian_pml' => 10,
            'target' => 50,
        ]);

        $this->getJson('/api/dashboard/ppl')->assertOk()->assertJsonPath('data.0.email', 'removed@example.test');
    }

    public function test_non_admin_credentials_cannot_sign_in_to_upload_area(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->post('/admin/login', ['email' => $viewer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_duplicate_import_is_reported_as_already_imported(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $content = 'same snapshot';
        ProgressUpload::create([
            'snapshot_date' => '2026-08-13',
            'version' => 1,
            'original_filename' => 'snapshot.xlsx',
            'file_checksum' => hash('sha256', $content),
            'status' => 'imported',
            'row_count' => 1,
            'imported_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/progress-uploads/validate', [
            'snapshot_date' => '2026-08-13',
            'file' => UploadedFile::fake()->createWithContent('snapshot.xlsx', $content),
        ])->assertOk()->assertJsonPath('already_imported', true);
    }

    public function test_only_admin_can_delete_a_snapshot_and_its_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $upload = ProgressUpload::create([
            'snapshot_date' => '2026-08-13',
            'version' => 1,
            'original_filename' => 'snapshot.xlsx',
            'stored_path' => 'progress-uploads/snapshot.xlsx',
            'file_checksum' => str_repeat('e', 64),
            'status' => 'imported',
            'row_count' => 1,
            'imported_at' => now(),
        ]);
        $row = ProgressSnapshotRow::create([
            'upload_id' => $upload->id,
            'assignment_key' => '5371010001000101:ppl-delete',
            'row_fingerprint' => str_repeat('f', 64),
            'row_number' => 2,
            'kode_subsls' => '5371010001000101',
            'ppl_id' => 'ppl-delete',
            'target' => 100,
        ]);

        $this->actingAs($viewer)->deleteJson('/api/admin/progress-uploads/'.$upload->id)->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/admin/progress-uploads/'.$upload->id)
            ->assertOk()
            ->assertJsonPath('message', 'Snapshot berhasil dihapus.');

        $this->assertDatabaseMissing('progress_uploads', ['id' => $upload->id]);
        $this->assertDatabaseMissing('progress_snapshot_rows', ['id' => $row->id]);
    }

    private function activeSnapshot(): void
    {
        $upload = ProgressUpload::create([
            'snapshot_date' => '2026-08-13',
            'version' => 1,
            'original_filename' => 'snapshot.xlsx',
            'file_checksum' => str_repeat('a', 64),
            'status' => 'imported',
            'row_count' => 1,
            'imported_at' => now(),
        ]);

        ProgressSnapshotRow::create([
            'upload_id' => $upload->id,
            'assignment_key' => '5371010001000101:ppl-public',
            'row_fingerprint' => str_repeat('b', 64),
            'row_number' => 2,
            'kode_subsls' => '5371010001000101',
            'nama_sls' => 'RT 001',
            'ppl_id' => 'ppl-public',
            'ppl_email' => 'ppl@example.test',
            'ppl_name' => 'Petugas Publik',
            'pml_name' => 'Pengawas Publik',
            'pml_email' => 'pml@example.test',
            'capaian_ppl' => 75,
            'capaian_pml' => 50,
            'target' => 100,
            'status_produktivitas' => 'Produktif',
            'jenis_mitra' => 'PPL',
        ]);
    }
}
