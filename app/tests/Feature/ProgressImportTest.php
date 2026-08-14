<?php

namespace Tests\Feature;

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

    private function workbook(int $ppl, int $pml): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Daftar Capaian_Harian');
        $sheet->getActiveSheet()->fromArray([
            ['No', 'Kode SubSLS', 'Nama SLS', 'Nama PPL', 'Email PPL', 'ID PPL', 'Nama PML', 'Email PML', 'Capaian PPL', 'Capaian PML', 'Target', 'Status Produktivitas', 'Status PPL Sobat', 'Status PML Sobat', 'Kategori Mitra', 'Link Assignment PPL', 'Jenis Mitra'],
            [1, '5371010001000101', 'RT 001', 'Petugas A', 'ppl@example.org', 'ppl-a', 'PML A', 'pml@example.org', $ppl, $pml, 200, 'Produktif', '-', '-', 'Mitra', 'https://example.org/assignment', 'PPL'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'se2026-');
        (new Xlsx($sheet))->save($path);

        return UploadedFile::fake()->createWithContent('snapshot.xlsx', file_get_contents($path));
    }
}
