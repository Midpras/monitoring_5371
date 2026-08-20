<?php

namespace Tests\Feature;

use App\Models\ProgressSnapshotRow;
use App\Models\ProgressUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PublicDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_operational_api_are_public(): void
    {
        $this->activeSnapshot();

        $this->get('/')->assertOk()->assertSee('id="dashboard"', false)->assertSee('href="/admin"', false);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('metrics.cumulative_ppl', 75);
        $this->getJson('/api/dashboard/timeseries')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/dashboard/ppl')->assertOk()->assertJsonPath('data.0.email', 'ppl@example.test');
        $this->getJson('/api/dashboard/pml')->assertOk()->assertJsonPath('data.0.email', 'pml@example.test');
        $this->getJson('/api/dashboard/daily-breakdown?type=ppl')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.workers.0.rows');
        $this->getJson('/api/dashboard/daily-breakdown?type=ppl&worker=ppl%40example.test')
            ->assertOk()
            ->assertJsonPath('data.0.workers.0.id', 'ppl@example.test')
            ->assertJsonPath('data.0.workers.0.rows.0.kode_subsls', '5371010001000101')
            ->assertJsonPath('data.0.workers.0.rows.0.nama_sls', 'RT 001')
            ->assertJsonPath('data.0.workers.0.rows.0.ppl', 75)
            ->assertJsonPath('data.0.workers.0.rows.0.pml', 50)
            ->assertJsonPath('data.0.workers.0.rows.0.progress_percent', 75);
        $this->getJson('/api/dashboard/daily-breakdown?type=pml&worker=pml%40example.test')
            ->assertOk()
            ->assertJsonPath('data.0.workers.0.id', 'pml@example.test')
            ->assertJsonPath('data.0.workers.0.rows.0.kode_subsls', '5371010001000101')
            ->assertJsonPath('data.0.workers.0.rows.0.nama_sls', 'RT 001')
            ->assertJsonPath('data.0.workers.0.rows.0.ppl', 75)
            ->assertJsonPath('data.0.workers.0.rows.0.pml', 50)
            ->assertJsonPath('data.0.workers.0.rows.0.progress_percent', 50);
        $this->getJson('/api/dashboard/filters')->assertOk()
            ->assertJsonPath('dates', ['2026-08-13'])
            ->assertJsonPath('ppl.0.label', 'ppl@example.test');
    }

    public function test_dashboard_returns_empty_data_when_no_active_snapshot_exists(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('snapshot', null)->assertJsonPath('metrics.target', 0);
        $this->getJson('/api/dashboard/timeseries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/dashboard/ppl')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/dashboard/pml')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/dashboard/filters')->assertOk()->assertJsonPath('dates', []);
    }

    public function test_daily_breakdown_returns_the_last_three_snapshots_with_worker_deltas(): void
    {
        foreach (range(0, 3) as $offset) {
            $upload = ProgressUpload::create([
                'snapshot_date' => now()->startOfDay()->subDays(3 - $offset)->toDateString(),
                'version' => 1,
                'original_filename' => "snapshot-$offset.xlsx",
                'file_checksum' => str_pad((string) ($offset + 1), 64, 'a'),
                'status' => 'imported',
                'imported_at' => now()->subDays(3 - $offset),
            ]);

            ProgressSnapshotRow::create([
                'upload_id' => $upload->id,
                'assignment_key' => '5371010001000101:ppl-daily',
                'row_fingerprint' => str_pad((string) ($offset + 1), 64, 'b'),
                'row_number' => 2,
                'kode_subsls' => '5371010001000101',
                'nama_sls' => 'RT 001',
                'ppl_id' => 'ppl-daily',
                'ppl_email' => 'ppl-daily@example.test',
                'pml_email' => 'pml-daily@example.test',
                'capaian_ppl' => 10 + $offset,
                'capaian_pml' => 5 + $offset,
                'target' => 100,
            ]);
        }

        $response = $this->getJson('/api/dashboard/daily-breakdown?type=ppl&worker=ppl-daily%40example.test&ppl%5B%5D=ppl-daily%40example.test')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.workers.0.id', 'ppl-daily@example.test')
            ->assertJsonPath('data.0.workers.0.email', 'ppl-daily@example.test')
            ->assertJsonPath('data.0.workers.0.daily', 1)
            ->assertJsonPath('data.0.workers.0.rows.0.daily', 1)
            ->assertJsonPath('data.0.workers.0.rows.0.ppl', 11)
            ->assertJsonPath('data.0.workers.0.rows.0.pml', 6)
            ->assertJsonPath('data.0.workers.0.rows.0.progress_percent', 11);

        $this->assertSame(
            now()->startOfDay()->subDays(2)->toDateString(),
            $response->json('data.0.date'),
        );
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

        $response = $this->getJson('/api/dashboard/ppl')->assertOk();

        $response->assertJsonFragment(['email' => 'removed@example.test']);
    }

    public function test_non_admin_credentials_cannot_sign_in_to_upload_area(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->post('/admin/login', [
            'email' => $viewer->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertSessionHasErrors('email')->assertSessionHasInput('remember', '1');

        $this->assertGuest();
    }

    public function test_remember_me_sets_a_persistent_admin_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'remember_token' => null]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect('/admin');
        $this->assertNotNull($response->getCookie(Auth::getRecallerName()));
        $this->assertNotNull($admin->fresh()->remember_token);
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
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('snapshot', null);
    }

    public function test_admin_purge_removes_abandoned_and_old_superseded_uploads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $old = ProgressUpload::create([
            'snapshot_date' => '2026-08-10', 'version' => 1, 'original_filename' => 'old.xlsx',
            'file_checksum' => str_repeat('1', 64), 'status' => 'imported', 'superseded_at' => now()->subDays(4),
        ]);
        $pending = ProgressUpload::create([
            'snapshot_date' => '2026-08-11', 'original_filename' => 'pending.xlsx',
            'file_checksum' => str_repeat('2', 64), 'status' => 'validated',
        ]);
        $active = ProgressUpload::create([
            'snapshot_date' => '2026-08-12', 'version' => 1, 'original_filename' => 'active.xlsx',
            'file_checksum' => str_repeat('3', 64), 'status' => 'imported',
        ]);

        $this->actingAs($admin)->postJson('/api/admin/progress-uploads/purge')
            ->assertOk()
            ->assertJsonPath('deleted', 2);
        $this->assertDatabaseMissing('progress_uploads', ['id' => $old->id]);
        $this->assertDatabaseMissing('progress_uploads', ['id' => $pending->id]);
        $this->assertDatabaseHas('progress_uploads', ['id' => $active->id]);
        $this->actingAs($admin)->getJson('/api/admin/progress-uploads')
            ->assertOk()
            ->assertJsonPath('data.0.filename', 'active.xlsx')
            ->assertJsonPath('data.0.status', 'imported');
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
