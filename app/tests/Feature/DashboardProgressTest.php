<?php

namespace Tests\Feature;

use App\Models\DashboardSetting;
use App\Models\ProgressSnapshotRow;
use App\Models\ProgressUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_deadline_drives_city_and_worker_daily_targets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DashboardSetting::query()->find(1)->update(['target_date' => '2026-08-20']);
        $this->snapshot('2026-08-15', 100, 50, 40, 'ppl@example.test', 'pml@example.test');

        $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonPath('deadline.date', '2026-08-20')
            ->assertJsonPath('deadline.days_remaining', 5)
            ->assertJsonPath('metrics.required_daily_ppl', 10)
            ->assertJsonPath('metrics.required_daily_pml', 12);

        $this->actingAs($admin)->patchJson('/api/admin/dashboard-settings', ['target_date' => '2026-08-22'])
            ->assertOk()->assertJsonPath('target_date', '2026-08-22');
    }

    public function test_reassigned_pml_receives_only_the_subsls_delta(): void
    {
        $this->snapshot('2026-08-14', 100, 50, 40, 'ppl@example.test', 'old-pml@example.test');
        $this->snapshot('2026-08-15', 100, 65, 47, 'ppl@example.test', 'new-pml@example.test');

        $response = $this->getJson('/api/dashboard/pml?sort=daily_deficit&direction=desc')->assertOk();
        $row = collect($response->json('data'))->firstWhere('email', 'new-pml@example.test');

        $this->assertSame(7, $row['daily_pml']);
        $this->assertSame(7, $row['recent'][1]['daily']);
        $this->assertSame(7, $response->json('data.0.daily_pml'));
    }

    public function test_worker_payload_contains_only_the_last_three_uploaded_dates(): void
    {
        foreach ([10, 12, 15, 17] as $day) {
            $this->snapshot('2026-08-'.$day, 100, $day, $day - 2, 'ppl@example.test', 'pml@example.test');
        }

        $response = $this->getJson('/api/dashboard/ppl')->assertOk();
        $recent = $response->json('data.0.recent');

        $this->assertCount(3, $recent);
        $this->assertSame(['2026-08-12', '2026-08-15', '2026-08-17'], array_column($recent, 'date'));
    }

    public function test_ppl_breakdown_totals_match_the_aggregated_subsls_rows(): void
    {
        $this->duplicateSubSlsSnapshot('2026-08-14', ['old-1' => 5, 'old-2' => 8]);
        $this->duplicateSubSlsSnapshot('2026-08-15', ['new-1' => 6, 'new-2' => 7]);

        $worker = $this->getJson('/api/dashboard/ppl')->assertOk()->json('data.0');
        $breakdown = $this->getJson('/api/dashboard/daily-breakdown?type=ppl&worker=ppl%40example.test')
            ->assertOk()->json('data.1.workers.0');

        $this->assertSame(13, $worker['recent'][1]['cumulative']);
        $this->assertSame(0, $worker['recent'][1]['daily']);
        $this->assertCount(1, $breakdown['rows']);
        $this->assertSame($breakdown['cumulative'], array_sum(array_column($breakdown['rows'], 'cumulative')));
        $this->assertSame($breakdown['daily'], array_sum(array_column($breakdown['rows'], 'daily')));
    }

    private function snapshot(string $date, int $target, int $ppl, int $pml, string $pplEmail, string $pmlEmail): void
    {
        $upload = ProgressUpload::create([
            'snapshot_date' => $date,
            'version' => 1,
            'original_filename' => $date.'.xlsx',
            'file_checksum' => hash('sha256', $date.$pplEmail.$pmlEmail),
            'status' => 'imported',
            'row_count' => 1,
            'imported_at' => now(),
        ]);

        ProgressSnapshotRow::create([
            'upload_id' => $upload->id,
            'assignment_key' => '5371010001000101|ppl-1',
            'row_fingerprint' => hash('sha256', $date.'row'),
            'row_number' => 2,
            'kode_subsls' => '5371010001000101',
            'nama_sls' => 'RT 001',
            'ppl_id' => 'ppl-1',
            'ppl_email' => $pplEmail,
            'pml_email' => $pmlEmail,
            'capaian_ppl' => $ppl,
            'capaian_pml' => $pml,
            'target' => $target,
        ]);
    }

    private function duplicateSubSlsSnapshot(string $date, array $assignments): void
    {
        $upload = ProgressUpload::create([
            'snapshot_date' => $date,
            'version' => 1,
            'original_filename' => $date.'.xlsx',
            'file_checksum' => hash('sha256', $date),
            'status' => 'imported',
            'row_count' => count($assignments),
            'imported_at' => now(),
        ]);

        foreach ($assignments as $assignment => $ppl) {
            ProgressSnapshotRow::create([
                'upload_id' => $upload->id,
                'assignment_key' => '5371010001000101|'.$assignment,
                'row_fingerprint' => hash('sha256', $date.$assignment),
                'row_number' => 2,
                'kode_subsls' => '5371010001000101',
                'nama_sls' => 'RT 001',
                'ppl_id' => $assignment,
                'ppl_email' => 'ppl@example.test',
                'pml_email' => 'pml@example.test',
                'capaian_ppl' => $ppl,
                'capaian_pml' => $ppl,
                'target' => 50,
            ]);
        }
    }
}
