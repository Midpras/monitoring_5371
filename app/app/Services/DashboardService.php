<?php

namespace App\Services;

use App\Models\ProgressSnapshotRow;
use App\Models\ProgressUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardService
{
    public function snapshots(?string $date): array
    {
        $current = ProgressUpload::active()
            ->when($date, fn ($query) => $query->whereDate('snapshot_date', $date))
            ->orderByDesc('snapshot_date')
            ->firstOrFail();
        $previous = ProgressUpload::active()->where('snapshot_date', '<', $current->snapshot_date)->orderByDesc('snapshot_date')->first();

        return [$current, $previous];
    }

    public function summary(?string $date, array $filters): array
    {
        [$current, $previous] = $this->snapshots($date);
        $now = $this->totals($this->rows($current->id, $filters));
        $before = $previous ? $this->totals($this->rows($previous->id, $filters)) : null;

        return [
            'snapshot' => $this->snapshotMeta($current),
            'comparison_snapshot' => $previous ? $this->snapshotMeta($previous) : null,
            'metrics' => [
                'target' => $now->target,
                'cumulative_ppl' => $now->ppl,
                'cumulative_pml' => $now->pml,
                'progress_percent' => $this->ratio($now->ppl, $now->target),
                'remaining' => $now->target - $now->ppl,
                'net_change_ppl' => $before ? $now->ppl - $before->ppl : null,
                'net_change_pml' => $before ? $now->pml - $before->pml : null,
                'pml_vs_ppl_percent' => $this->ratio($now->pml, $now->ppl),
                'pml_vs_target_percent' => $this->ratio($now->pml, $now->target),
                'pending_review' => max($now->ppl - $now->pml, 0),
            ],
        ];
    }

    public function timeSeries(array $filters, ?string $from, ?string $to): array
    {
        $uploads = ProgressUpload::active()->when($from, fn ($q) => $q->whereDate('snapshot_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('snapshot_date', '<=', $to))->orderBy('snapshot_date')->get();
        $previous = null;

        return $uploads->map(function (ProgressUpload $upload) use ($filters, &$previous) {
            $totals = $this->totals($this->rows($upload->id, $filters));
            $point = [
                'date' => $upload->snapshot_date->toDateString(), 'target' => $totals->target,
                'ppl' => $totals->ppl, 'pml' => $totals->pml,
                'daily_ppl' => $previous ? $totals->ppl - $previous->ppl : null,
                'daily_pml' => $previous ? $totals->pml - $previous->pml : null,
            ];
            $previous = $totals;

            return $point;
        })->values()->all();
    }

    public function ppl(?string $date, array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        [$current, $previous] = $this->snapshots($date);
        $currentRows = $this->workerTotals($this->rows($current->id, $filters), "COALESCE(NULLIF(LOWER(ppl_email), ''), LOWER(ppl_id))");
        $previousRows = $previous ? $this->workerTotals($this->rows($previous->id, $filters), "COALESCE(NULLIF(LOWER(ppl_email), ''), LOWER(ppl_id))") : collect();
        $rows = $this->mergeWorkerTotals($currentRows, $previousRows, 'ppl');

        return $this->paginate($rows, $sort, $direction, $page, $perPage);
    }

    public function pml(?string $date, array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        [$current, $previous] = $this->snapshots($date);
        $currentRows = $this->workerTotals($this->rows($current->id, $filters), "COALESCE(NULLIF(LOWER(pml_email), ''), '__unassigned')");
        $previousRows = $previous ? $this->workerTotals($this->rows($previous->id, $filters), "COALESCE(NULLIF(LOWER(pml_email), ''), '__unassigned')") : collect();
        $rows = $this->mergeWorkerTotals($currentRows, $previousRows, 'pml');

        return $this->paginate($rows, $sort, $direction, $page, $perPage);
    }

    public function breakdown(?string $date, array $filters, string $type, string $worker): array
    {
        [$current] = $this->snapshots($date);
        $key = $type === 'ppl'
            ? "COALESCE(NULLIF(LOWER(ppl_email), ''), LOWER(ppl_id))"
            : "COALESCE(NULLIF(LOWER(pml_email), ''), '__unassigned')";
        $rows = $this->rows($current->id, $filters)
            ->whereRaw("$key = ?", [strtolower($worker)])
            ->selectRaw('kode_subsls, MAX(nama_sls) as nama_sls, MAX(ppl_email) as ppl_email, MAX(pml_email) as pml_email, COUNT(*) as assignments, COALESCE(SUM(target), 0) as target, COALESCE(SUM(capaian_ppl), 0) as ppl, COALESCE(SUM(capaian_pml), 0) as pml, MAX(status_produktivitas) as status_produktivitas')
            ->groupBy('kode_subsls')
            ->orderBy('kode_subsls')
            ->get();

        return [
            'data' => $rows->map(fn ($row) => [
                'kode_subsls' => $row->kode_subsls,
                'nama_sls' => $row->nama_sls ?: 'Nama SLS tidak tersedia',
                'ppl_email' => $row->ppl_email,
                'pml_email' => $row->pml_email,
                'assignments' => (int) $row->assignments,
                'target' => (int) $row->target,
                'ppl' => (int) $row->ppl,
                'pml' => (int) $row->pml,
                'status_produktivitas' => $row->status_produktivitas,
            ])->values()->all(),
            'meta' => ['total' => $rows->count(), 'type' => $type, 'worker' => $worker],
        ];
    }

    public function filters(?string $date): array
    {
        [$current] = $this->snapshots($date);
        $rows = ProgressSnapshotRow::query()->where('upload_id', $current->id);

        return [
            'dates' => ProgressUpload::active()->orderByDesc('snapshot_date')->get()->map(fn (ProgressUpload $upload) => $upload->snapshot_date->toDateString())->all(),
            'pml' => (clone $rows)->whereNotNull('pml_email')->selectRaw('LOWER(pml_email) as value, MAX(pml_email) as label')->groupByRaw('LOWER(pml_email)')->orderBy('label')->get(),
            'ppl' => (clone $rows)->whereNotNull('ppl_email')->selectRaw('LOWER(ppl_email) as value, MAX(ppl_email) as label')->groupByRaw('LOWER(ppl_email)')->orderBy('label')->get(),
            'productivity_statuses' => (clone $rows)->whereNotNull('status_produktivitas')->distinct()->orderBy('status_produktivitas')->pluck('status_produktivitas'),
            'jenis_mitra' => (clone $rows)->whereNotNull('jenis_mitra')->distinct()->orderBy('jenis_mitra')->pluck('jenis_mitra'),
        ];
    }

    private function rows(int $uploadId, array $filters): Builder
    {
        $query = ProgressSnapshotRow::query()->where('upload_id', $uploadId);
        foreach (['pml' => 'pml_email', 'ppl' => 'ppl_email', 'status' => 'status_produktivitas', 'jenis_mitra' => 'jenis_mitra'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $filter === 'pml' || $filter === 'ppl'
                    ? $query->whereRaw("LOWER(COALESCE(NULLIF($column, ''), '')) = ?", [strtolower($filters[$filter])])
                    : $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('ppl_email', 'like', $term)->orWhere('pml_email', 'like', $term)->orWhere('ppl_name', 'like', $term)->orWhere('pml_name', 'like', $term)->orWhere('nama_sls', 'like', $term)->orWhere('kode_subsls', 'like', $term));
        }

        return $query;
    }

    private function totals(Builder $query): object
    {
        return $query->selectRaw('COALESCE(SUM(target), 0) as target, COALESCE(SUM(capaian_ppl), 0) as ppl, COALESCE(SUM(capaian_pml), 0) as pml')->first();
    }

    private function workerTotals(Builder $query, string $key): Collection
    {
        return $query->selectRaw("$key as worker_key, MAX(ppl_email) as ppl_email, MAX(pml_email) as pml_email, COUNT(*) as assignments, COALESCE(SUM(target), 0) as target, COALESCE(SUM(capaian_ppl), 0) as ppl, COALESCE(SUM(capaian_pml), 0) as pml")
            ->groupByRaw($key)->get()->keyBy('worker_key');
    }

    private function mergeWorkerTotals(Collection $current, Collection $previous, string $type): Collection
    {
        return $current->keys()->merge($previous->keys())->unique()->map(function ($key) use ($current, $previous, $type) {
            $now = $current->get($key);
            $before = $previous->get($key);
            $target = (int) ($now?->target ?? $before?->target ?? 0);
            $ppl = (int) ($now?->ppl ?? 0);
            $pml = (int) ($now?->pml ?? 0);
            $previousPpl = (int) ($before?->ppl ?? 0);
            $previousPml = (int) ($before?->pml ?? 0);
            $email = $type === 'ppl'
                ? ($now?->ppl_email ?? $before?->ppl_email ?? 'Email PPL tidak tersedia')
                : ($now?->pml_email ?? $before?->pml_email ?? 'Email PML tidak tersedia');

            return [
                'id' => $key, 'email' => $email, 'pml_email' => $type === 'ppl' ? ($now?->pml_email ?? $before?->pml_email) : null,
                'assignments' => (int) ($now?->assignments ?? 0), 'target' => $target, 'ppl' => $ppl, 'pml' => $pml,
                'daily_ppl' => $ppl - $previousPpl, 'daily_pml' => $pml - $previousPml,
                'remaining' => $target - $ppl, 'progress_percent' => $this->ratio($ppl, $target),
                'pml_vs_ppl_percent' => $this->ratio($pml, $ppl), 'pending_review' => max($ppl - $pml, 0),
            ];
        })->values();
    }

    private function paginate(Collection $rows, string $sort, string $direction, int $page, int $perPage): array
    {
        $allowed = ['email', 'target', 'ppl', 'pml', 'daily_ppl', 'daily_pml', 'progress_percent', 'remaining', 'pending_review'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'ppl';
        $rows = $rows->sortBy($sort, SORT_REGULAR, $direction === 'asc')->values();
        $total = $rows->count();

        return ['data' => $rows->forPage($page, $perPage)->values(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    private function snapshotMeta(ProgressUpload $upload): array
    {
        return ['date' => $upload->snapshot_date->toDateString(), 'version' => $upload->version, 'imported_at' => $upload->imported_at?->toIso8601String()];
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : null;
    }
}
