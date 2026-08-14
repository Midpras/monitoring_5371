<?php

namespace App\Http\Controllers;

use App\Models\ProgressUpload;
use App\Services\ProgressImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressUploadController extends Controller
{
    public function __construct(private ProgressImportService $imports) {}

    public function index(): JsonResponse
    {
        $uploads = ProgressUpload::with('uploader:id,name')->latest('snapshot_date')->latest('version')->paginate(20);

        return response()->json($uploads);
    }

    public function show(ProgressUpload $progressUpload): JsonResponse
    {
        return response()->json($this->payload($progressUpload->load('uploader:id,name')));
    }

    public function validateUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot_date' => ['required', 'date'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);
        $checksum = hash_file('sha256', $data['file']->getRealPath());
        $duplicate = ProgressUpload::query()->whereDate('snapshot_date', $data['snapshot_date'])
            ->where('file_checksum', $checksum)->whereIn('status', ['validated', 'importing', 'imported'])->first();
        if ($duplicate) {
            return response()->json(array_merge($this->payload($duplicate->load('uploader:id,name')), ['already_imported' => $duplicate->status === 'imported']));
        }

        $upload = $this->imports->validate($data['file'], $data['snapshot_date'], $request->user());
        $sameContent = ProgressUpload::query()->where('file_checksum', $checksum)->whereDate('snapshot_date', '!=', $data['snapshot_date'])->where('status', 'imported')->first();
        if ($sameContent) {
            $warnings = $upload->validation_warnings ?? [];
            $warnings[] = ['message' => 'Isi file sama dengan snapshot '.$sameContent->snapshot_date->toDateString().'; konfirmasi hanya jika ini memang tanggal snapshot baru.'];
            $upload->update(['validation_warnings' => $warnings]);
        }

        return response()->json($this->payload($upload->fresh()));
    }

    public function confirm(ProgressUpload $progressUpload): JsonResponse
    {
        try {
            $upload = $this->imports->import($progressUpload);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($upload));
    }

    public function destroy(ProgressUpload $progressUpload): JsonResponse
    {
        try {
            $this->imports->delete($progressUpload);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Snapshot berhasil dihapus.']);
    }

    private function payload(ProgressUpload $upload): array
    {
        return [
            'id' => $upload->id, 'snapshot_date' => $upload->snapshot_date->toDateString(), 'version' => $upload->version,
            'filename' => $upload->original_filename, 'status' => $upload->status, 'row_count' => $upload->row_count,
            'validation_error_count' => $upload->validation_error_count, 'errors' => $upload->validation_errors ?? [],
            'warnings' => $upload->validation_warnings ?? [], 'preview' => $upload->validation_preview ?? [],
            'imported_at' => $upload->imported_at?->toIso8601String(), 'superseded_at' => $upload->superseded_at?->toIso8601String(),
            'uploaded_by' => $upload->uploader?->name, 'already_imported' => false,
        ];
    }
}
