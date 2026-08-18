<?php

namespace Tests\Feature;

use App\Models\ProgressUpload;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ProgressImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProgressImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_values_are_not_summed_across_uploads(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $importer = app(ProgressImportService::class);
        $importer->import($importer->validate($this->workbook(100, 75), '2026-08-10', $user));
        $importer->import($importer->validate($this->workbook(125, 90), '2026-08-12', $user));

        $summary = app(DashboardService::class)->summary('2026-08-12', []);

        $this->assertSame(125, $summary['metrics']['cumulative_ppl']);
        $this->assertSame(25, $summary['metrics']['net_change_ppl']);
        $this->assertSame(90, $summary['metrics']['cumulative_pml']);
        $this->assertSame(25, app(DashboardService::class)->ppl('2026-08-12', [], 'ppl', 'desc', 1, 25)['data'][0]['daily_ppl']);
        $this->assertSame(35, app(DashboardService::class)->pml('2026-08-12', [], 'pml', 'desc', 1, 25)['data'][0]['pending_review']);
    }

    public function test_invalid_upload_is_rejected_and_removed_after_errors_are_returned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/progress-uploads/validate', [
            'snapshot_date' => '2026-08-13',
            'file' => $this->workbookValues('invalid', 75),
        ])->assertOk()
            ->assertJsonPath('status', 'invalid')
            ->assertJsonPath('errors.0.column', 'Capaian PPL');

        $this->assertDatabaseCount('progress_uploads', 0);
    }

    public function test_warning_requires_confirmation_and_is_stored_on_import(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson('/api/admin/progress-uploads/validate', [
            'snapshot_date' => '2026-08-13',
            'file' => $this->workbookValues(100, 125),
        ])->assertOk()
            ->assertJsonPath('status', 'validated');
        $uploadId = $response->json('id');

        $this->actingAs($admin)->postJson('/api/admin/progress-uploads/'.$uploadId.'/confirm')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konfirmasi diperlukan untuk melanjutkan dengan peringatan validasi.');
        $this->assertDatabaseHas('progress_uploads', ['id' => $uploadId, 'status' => 'validated']);

        $this->actingAs($admin)->postJson('/api/admin/progress-uploads/'.$uploadId.'/confirm', ['confirm_warnings' => true])
            ->assertOk()
            ->assertJsonPath('status', 'imported');
        $this->assertNotEmpty(ProgressUpload::find($uploadId)->validation_warnings);
    }

    public function test_last_confirmed_version_becomes_active_for_the_same_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $importer = app(ProgressImportService::class);
        $first = $importer->import($importer->validate($this->workbook(100, 75), '2026-08-13', $admin));
        $second = $importer->import($importer->validate($this->workbook(125, 90), '2026-08-13', $admin));

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertTrue($second->fresh()->superseded_at === null);
        $this->assertSame(125, app(DashboardService::class)->summary('2026-08-13', [])['metrics']['cumulative_ppl']);
    }

    private function workbook(int $ppl, int $pml): UploadedFile
    {
        return $this->workbookValues($ppl, $pml);
    }

    private function workbookValues(mixed $ppl, mixed $pml, mixed $target = 200): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Daftar Capaian_Harian');
        $sheet->getActiveSheet()->fromArray([
            ['No', 'Kode SubSLS', 'Nama SLS', 'Nama PPL', 'Email PPL', 'ID PPL', 'Nama PML', 'Email PML', 'Capaian PPL', 'Capaian PML', 'Target', 'Status Produktivitas', 'Status PPL Sobat', 'Status PML Sobat', 'Kategori Mitra', 'Link Assignment PPL', 'Jenis Mitra'],
            [1, '5371010001000101', 'RT 001', 'Petugas A', 'ppl@example.org', 'ppl-a', 'PML A', 'pml@example.org', $ppl, $pml, $target, 'Produktif', '-', '-', 'Mitra', 'https://example.org/assignment', 'PPL'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'se2026-');
        (new Xlsx($sheet))->save($path);

        return UploadedFile::fake()->createWithContent('snapshot.xlsx', file_get_contents($path));
    }
}
