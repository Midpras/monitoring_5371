<?php

namespace App\Services;

use App\Models\ProgressSnapshotRow;
use App\Models\ProgressUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ProgressImportService
{
    private const SHEET = 'Daftar Capaian_Harian';

    private const COLUMNS = [
        'No', 'Kode SubSLS', 'Nama SLS', 'Nama PPL', 'Email PPL', 'ID PPL', 'Nama PML',
        'Email PML', 'Capaian PPL', 'Capaian PML', 'Target', 'Status Produktivitas',
        'Status PPL Sobat', 'Status PML Sobat', 'Kategori Mitra', 'Link Assignment PPL', 'Jenis Mitra',
    ];

    public function validate(UploadedFile $file, string $snapshotDate, User $user): ProgressUpload
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('progress-uploads/pending', Str::uuid().'.xlsx', 'local');
        if (! $path) {
            throw new RuntimeException('File tidak dapat disimpan. Periksa volume storage aplikasi.');
        }
        $upload = ProgressUpload::create([
            'snapshot_date' => $snapshotDate,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_checksum' => $checksum,
            'uploaded_by' => $user->id,
        ]);

        try {
            $result = $this->parse(Storage::disk('local')->path($path));
        } catch (\Throwable $exception) {
            $result = ['rows' => [], 'errors' => [['message' => 'File tidak dapat dibaca: '.$exception->getMessage()]], 'warnings' => []];
        }

        $upload->update([
            'status' => $result['errors'] === [] ? 'validated' : 'invalid',
            'row_count' => count($result['rows']),
            'validation_error_count' => count($result['errors']),
            'validation_errors' => $result['errors'],
            'validation_warnings' => $result['warnings'],
            'validation_preview' => array_slice(array_map(fn (array $row) => [
                'kode_subsls' => $row['kode_subsls'], 'nama_sls' => $row['nama_sls'],
                'ppl_id' => $row['ppl_id'], 'ppl_name' => $row['ppl_name'], 'target' => $row['target'],
                'capaian_ppl' => $row['capaian_ppl'], 'capaian_pml' => $row['capaian_pml'],
            ], $result['rows']), 0, 12),
            'validated_at' => now(),
        ]);

        return $upload->fresh();
    }

    public function import(ProgressUpload $upload): ProgressUpload
    {
        if ($upload->status !== 'validated' || ! $upload->stored_path) {
            throw new RuntimeException('Upload belum siap untuk diimpor.');
        }

        $result = $this->parse(Storage::disk('local')->path($upload->stored_path));
        if ($result['errors'] !== []) {
            $upload->update(['status' => 'invalid', 'validation_errors' => $result['errors'], 'validation_error_count' => count($result['errors'])]);
            throw new RuntimeException('Validasi file berubah dan impor dibatalkan.');
        }

        DB::transaction(function () use ($upload, $result) {
            $previous = ProgressUpload::active()
                ->whereDate('snapshot_date', $upload->snapshot_date)
                ->lockForUpdate()
                ->first();
            $nextVersion = ((int) ProgressUpload::whereDate('snapshot_date', $upload->snapshot_date)->max('version')) + 1;

            if ($previous) {
                $previous->update(['superseded_at' => now()]);
            }

            $upload->update(['version' => $nextVersion, 'status' => 'importing']);

            foreach (array_chunk($result['rows'], 300) as $chunk) {
                ProgressSnapshotRow::query()->insert(array_map(fn (array $row) => $row + [
                    'upload_id' => $upload->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk));
            }

            $upload->update([
                'status' => 'imported',
                'row_count' => count($result['rows']),
                'validation_warnings' => $result['warnings'],
                'imported_at' => now(),
            ]);
        });

        return $upload->fresh();
    }

    public function delete(ProgressUpload $upload): void
    {
        if ($upload->status === 'importing') {
            throw new RuntimeException('Snapshot sedang diimpor dan belum dapat dihapus.');
        }

        $storedPath = $upload->stored_path;
        DB::transaction(function () use ($upload) {
            $upload->rows()->delete();
            $upload->delete();
        });

        if ($storedPath) {
            Storage::disk('local')->delete($storedPath);
        }
    }

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} */
    private function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName(self::SHEET);
        if (! $sheet) {
            return ['rows' => [], 'errors' => [['message' => 'Worksheet "'.self::SHEET.'" tidak ditemukan.']], 'warnings' => []];
        }

        $data = $sheet->toArray(null, true, true, true);
        $headers = array_map(fn ($value) => trim((string) $value), $data[1] ?? []);
        $columns = array_flip($headers);
        $missing = array_values(array_filter(self::COLUMNS, fn ($column) => ! isset($columns[$column])));
        if ($missing !== []) {
            return ['rows' => [], 'errors' => [['message' => 'Kolom wajib tidak ditemukan.', 'columns' => $missing]], 'warnings' => []];
        }

        $rows = [];
        $errors = [];
        $warnings = [];
        $seen = [];
        foreach (array_slice($data, 1, null, true) as $rowNumber => $cells) {
            if (count(array_filter($cells, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $source = fn (string $column) => $this->nullable($cells[$columns[$column]] ?? null);
            $kode = $source('Kode SubSLS');
            $pplId = $source('ID PPL');
            $target = $this->integer($source('Target'), $rowNumber, 'Target', $errors, true);
            $ppl = $this->integer($source('Capaian PPL'), $rowNumber, 'Capaian PPL', $errors);
            $pml = $this->integer($source('Capaian PML'), $rowNumber, 'Capaian PML', $errors);

            if (! $kode || ! $pplId || $target === null) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Kode SubSLS, ID PPL, dan Target wajib diisi.'];

                continue;
            }

            $assignmentKey = $kode.'|'.$pplId;
            if (isset($seen[$assignmentKey])) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Duplikat assignment key dengan baris '.$seen[$assignmentKey].'.'];

                continue;
            }
            $seen[$assignmentKey] = $rowNumber;

            $url = $source('Link Assignment PPL');
            if ($url && ! filter_var($url, FILTER_VALIDATE_URL)) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'Link Assignment PPL tidak valid, tetapi tetap disimpan untuk audit.'];
            }

            $row = [
                'assignment_key' => $assignmentKey,
                'row_number' => $rowNumber,
                'kode_subsls' => $kode,
                'nama_sls' => $source('Nama SLS'),
                'ppl_id' => $pplId,
                'ppl_name' => $source('Nama PPL'),
                'ppl_email' => $source('Email PPL'),
                'pml_name' => $source('Nama PML'),
                'pml_email' => $source('Email PML'),
                'capaian_ppl' => $ppl,
                'capaian_pml' => $pml,
                'target' => $target,
                'status_produktivitas' => $source('Status Produktivitas'),
                'status_ppl_sobat' => $source('Status PPL Sobat'),
                'status_pml_sobat' => $source('Status PML Sobat'),
                'kategori_mitra' => $source('Kategori Mitra'),
                'assignment_url' => $url,
                'jenis_mitra' => $source('Jenis Mitra'),
            ];
            $row['row_fingerprint'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $rows[] = $row;
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['message' => 'Snapshot tidak memiliki baris data.'];
        }

        return compact('rows', 'errors', 'warnings');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, ['', '-'], true) ? null : $value;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function integer(?string $value, int $row, string $column, array &$errors, bool $required = false): ?int
    {
        if ($value === null) {
            return $required ? null : null;
        }
        if (! preg_match('/^\d+$/', $value)) {
            $errors[] = ['row' => $row, 'column' => $column, 'message' => 'Nilai harus berupa bilangan bulat non-negatif.'];

            return null;
        }

        return (int) $value;
    }
}
