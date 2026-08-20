<?php

namespace App\Services;

use App\Models\DashboardSetting;
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
            ->first();
        if (! $current) {
            return [null, null];
        }

        $previous = ProgressUpload::active()
            ->where('snapshot_date', '<', $current->snapshot_date)
            ->orderByDesc('snapshot_date')
            ->first();

        return [$current, $previous];
    }

    public function summary(?string $date, array $filters): array
    {
        [$current, $previous] = $this->snapshots($date);
        if (! $current) {
            return [
                'snapshot' => null,
                'comparison_snapshot' => null,
                'deadline' => $this->deadlinePayload(null, null),
                'metrics' => [
                    'target' => 0, 'cumulative_ppl' => 0, 'cumulative_pml' => 0, 'progress_percent' => null,
                    'remaining' => 0, 'net_change_ppl' => null, 'net_change_pml' => null,
                    'pml_vs_ppl_percent' => null, 'pml_vs_target_percent' => null, 'pending_review' => 0,
                    'required_daily_ppl' => null, 'required_daily_pml' => null,
                ],
            ];
        }

        $currentRows = $this->rows($current->id, $filters)->get();
        $previousRows = $previous ? $this->rows($previous->id, [])->get() : collect();
        $now = $this->sumTotals($currentRows);
        $beforeRows = $previous ? $this->comparisonRows($currentRows, $previousRows) : collect();
        $before = $previous ? $this->sumTotals($beforeRows) : null;
        $deadline = $this->deadlinePayload($current, $now);

        return [
            'snapshot' => $this->snapshotMeta($current),
            'comparison_snapshot' => $previous ? $this->snapshotMeta($previous) : null,
            'deadline' => $deadline,
            'metrics' => [
                'target' => $now->target,
                'cumulative_ppl' => $now->ppl,
                'cumulative_pml' => $now->pml,
                'progress_percent' => $this->ratio($now->ppl, $now->target),
                'remaining' => $now->target - $now->ppl,
                'net_change_ppl' => $previous ? $this->dailyTotal($currentRows, $previousRows, 'ppl') : null,
                'net_change_pml' => $previous ? $this->dailyTotal($currentRows, $previousRows, 'pml') : null,
                'pml_vs_ppl_percent' => $this->ratio($now->pml, $now->ppl),
                'pml_vs_target_percent' => $this->ratio($now->pml, $now->target),
                'pending_review' => max($now->ppl - $now->pml, 0),
                'required_daily_ppl' => $deadline['required_daily_ppl'],
                'required_daily_pml' => $deadline['required_daily_pml'],
                'target_change' => $previous ? $now->target - $before->target : null,
            ],
        ];
    }

    public function timeSeries(array $filters, ?string $from, ?string $to): array
    {
        $uploads = ProgressUpload::active()
            ->when($from, fn ($q) => $q->whereDate('snapshot_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('snapshot_date', '<=', $to))
            ->orderBy('snapshot_date')
            ->get();
        $previous = null;

        return $uploads->map(function (ProgressUpload $upload) use ($filters, &$previous) {
            $currentRows = $this->rows($upload->id, $filters)->get();
            $previousRows = $previous ? $this->rows($previous->id, [])->get() : collect();
            $totals = $this->sumTotals($currentRows);
            $point = [
                'date' => $upload->snapshot_date->toDateString(),
                'target' => $totals->target,
                'ppl' => $totals->ppl,
                'pml' => $totals->pml,
                'daily_ppl' => $previous ? $this->dailyTotal($currentRows, $previousRows, 'ppl') : null,
                'daily_pml' => $previous ? $this->dailyTotal($currentRows, $previousRows, 'pml') : null,
            ];
            $previous = $upload;

            return $point;
        })->values()->all();
    }

    public function ppl(?string $date, array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        return $this->paginateWorkers($this->workerTable($date, $filters, 'ppl'), $sort, $direction, $page, $perPage, 'ppl');
    }

    public function pml(?string $date, array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        return $this->paginateWorkers($this->workerTable($date, $filters, 'pml'), $sort, $direction, $page, $perPage, 'pml');
    }

    public function dailyBreakdown(?string $date, array $filters, string $type, ?string $worker = null): array
    {
        [$snapshots, $baseline] = $this->recentSnapshots($date);
        $workerKey = $worker ? strtolower(trim($worker)) : null;
        $includeDetails = $workerKey !== null;
        $previousRows = $baseline ? $this->rows($baseline->id, [])->get() : collect();

        $data = $snapshots->map(function (ProgressUpload $upload) use (&$previousRows, $filters, $type, $workerKey, $includeDetails) {
            $currentRows = $this->rows($upload->id, $filters)->get();
            $allCurrentRows = $this->rows($upload->id, [])->get();
            $groups = $this->groupWorkers($currentRows, $type);
            $dailyByWorker = $this->dailyByWorker($currentRows, $previousRows, $type);
            $dailyByAssignment = $this->dailyByAssignment($currentRows, $previousRows, $type);
            if ($workerKey !== null) {
                $groups = $groups->filter(fn (Collection $rows, string $key) => $key === $workerKey);
            }

            $workers = $groups->map(function (Collection $rows, string $key) use ($type, $workerKey, $includeDetails, $dailyByWorker, $dailyByAssignment) {
                $details = $rows->groupBy('kode_subsls')->map(function (Collection $subSlsRows) use ($type, $dailyByAssignment) {
                    $metric = $type === 'ppl' ? 'capaian_ppl' : 'capaian_pml';
                    $row = $subSlsRows->first();
                    $target = (int) $subSlsRows->sum(fn (ProgressSnapshotRow $item) => (int) ($item->target ?? 0));
                    $ppl = (int) $subSlsRows->sum(fn (ProgressSnapshotRow $item) => (int) ($item->capaian_ppl ?? 0));
                    $pml = (int) $subSlsRows->sum(fn (ProgressSnapshotRow $item) => (int) ($item->capaian_pml ?? 0));
                    $daily = $subSlsRows->pluck('assignment_key')
                        ->filter(fn (string $assignment) => array_key_exists($assignment, $dailyByAssignment))
                        ->sum(fn (string $assignment) => $dailyByAssignment[$assignment]);
                    $hasDaily = $subSlsRows->contains(fn (ProgressSnapshotRow $item) => array_key_exists($item->assignment_key, $dailyByAssignment));
                    $current = $type === 'ppl' ? $ppl : $pml;

                    return [
                        'kode_subsls' => $row->kode_subsls,
                        'nama_sls' => $row->nama_sls ?: 'Nama SLS tidak tersedia',
                        'ppl_email' => $row->ppl_email,
                        'pml_email' => $row->pml_email,
                        'target' => $target,
                        'ppl' => $ppl,
                        'pml' => $pml,
                        'cumulative' => $current,
                        'daily' => $hasDaily ? (int) $daily : null,
                        'progress_percent' => $this->ratio($current, $target),
                        'status_produktivitas' => $row->status_produktivitas,
                    ];
                })->values();
                $metric = $type === 'ppl' ? 'ppl' : 'pml';
                $target = (int) $details->sum('target');
                $cumulative = (int) $details->sum($metric === 'ppl' ? 'ppl' : 'pml');

                return [
                    'id' => $key,
                    'email' => $type === 'ppl'
                        ? ($rows->first()->ppl_email ?: 'Email PPL tidak tersedia')
                        : ($rows->first()->pml_email ?: 'Email PML tidak tersedia'),
                    'assignments' => $details->count(),
                    'target' => $target,
                    'cumulative' => $cumulative,
                    'daily' => $dailyByWorker[$key] ?? null,
                    'progress_percent' => $this->ratio($cumulative, $target),
                    'rows' => $includeDetails && $workerKey === $key ? $details : [],
                ];
            })->values();

            $previousRows = $allCurrentRows;

            return [
                'date' => $upload->snapshot_date->toDateString(),
                'version' => $upload->version,
                'imported_at' => $upload->imported_at?->toIso8601String(),
                'workers' => $workers,
                'meta' => ['total_workers' => $workers->count(), 'total_subsls' => $workers->sum('assignments')],
            ];
        })->values()->all();

        return ['data' => $data, 'meta' => ['type' => $type, 'date' => $date]];
    }

    public function filters(?string $date): array
    {
        [$current] = $this->snapshots($date);
        if (! $current) {
            return ['dates' => [], 'pml' => [], 'ppl' => [], 'productivity_statuses' => []];
        }
        $rows = ProgressSnapshotRow::query()->where('upload_id', $current->id);

        return [
            'dates' => ProgressUpload::active()
                ->orderByDesc('snapshot_date')
                ->get(['snapshot_date'])
                ->map(fn (ProgressUpload $upload) => $upload->snapshot_date->toDateString())
                ->all(),
            'pml' => (clone $rows)->whereNotNull('pml_email')->selectRaw('LOWER(pml_email) as value, MAX(pml_email) as label')->groupByRaw('LOWER(pml_email)')->orderBy('label')->get(),
            'ppl' => (clone $rows)->whereNotNull('ppl_email')->selectRaw('LOWER(ppl_email) as value, MAX(ppl_email) as label')->groupByRaw('LOWER(ppl_email)')->orderBy('label')->get(),
            'productivity_statuses' => (clone $rows)->whereNotNull('status_produktivitas')->distinct()->orderBy('status_produktivitas')->pluck('status_produktivitas'),
        ];
    }

    private function workerTable(?string $date, array $filters, string $type): Collection
    {
        [$current, $previous] = $this->snapshots($date);
        if (! $current) {
            return collect();
        }

        [$recentSnapshots, $baseline] = $this->recentSnapshots($date);
        $rowsByDate = [];
        $groupsByDate = [];
        $dailyByDate = [];
        $previousRows = $baseline ? $this->rows($baseline->id, [])->get() : collect();

        foreach ($recentSnapshots as $upload) {
            $rows = $this->rows($upload->id, $filters)->get();
            $rowsByDate[$upload->snapshot_date->toDateString()] = $rows;
            $groupsByDate[$upload->snapshot_date->toDateString()] = $this->groupWorkers($rows, $type);
            $dailyByDate[$upload->snapshot_date->toDateString()] = $this->dailyByWorker($rows, $previousRows, $type);
            $previousRows = $rows;
        }

        $currentRows = $rowsByDate[$current->snapshot_date->toDateString()] ?? collect();
        $previousRows = $previous ? $this->rows($previous->id, $filters)->get() : collect();
        $currentGroups = $this->groupWorkers($currentRows, $type);
        $previousGroups = $this->groupWorkers($previousRows, $type);
        $keys = $currentGroups->keys()->merge($previousGroups->keys())->unique();
        $settings = $this->deadlineSettings();
        $dates = $recentSnapshots->map(fn (ProgressUpload $upload) => $upload->snapshot_date->toDateString())->values();

        return $keys->map(function (string $key) use ($currentGroups, $previousGroups, $groupsByDate, $dailyByDate, $dates, $type, $settings, $current) {
            $group = $currentGroups->get($key) ?: $previousGroups->get($key);
            $cumulativePpl = (int) $group->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_ppl ?? 0));
            $cumulativePml = (int) $group->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_pml ?? 0));
            $target = (int) $group->sum(fn (ProgressSnapshotRow $row) => (int) ($row->target ?? 0));
            $cumulative = $type === 'ppl' ? $cumulativePpl : $cumulativePml;
            $recent = $dates->map(function (string $date) use ($groupsByDate, $dailyByDate, $key, $type, $dates) {
                $rows = $groupsByDate[$date]->get($key, collect());
                $previousDate = $dates->before($date);
                $previousGroup = $previousDate ? $groupsByDate[$previousDate]->get($key, collect()) : collect();
                $target = (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->target ?? 0));
                $previousTarget = (int) $previousGroup->sum(fn (ProgressSnapshotRow $row) => (int) ($row->target ?? 0));
                $cumulative = $rows->isEmpty() ? null : ($type === 'ppl'
                    ? (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_ppl ?? 0))
                    : (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_pml ?? 0)));
                return [
                    'date' => $date,
                    'daily' => array_key_exists($key, $dailyByDate[$date]) ? $dailyByDate[$date][$key] : null,
                    'cumulative' => $cumulative,
                    'target' => $target,
                    'target_change' => $previousDate ? $target - $previousTarget : null,
                ];
            })->values()->all();
            $latestDaily = $recent ? end($recent)['daily'] : null;
            $deadline = $this->deadlineFor(
                $current,
                $cumulativePpl,
                $cumulativePml,
                $target,
                $settings,
            );
            $required = $deadline['required_daily_'.$type];

            return [
                'id' => $key,
                'email' => $type === 'ppl'
                    ? ($group->first()->ppl_email ?: 'Email PPL tidak tersedia')
                    : ($group->first()->pml_email ?: 'Email PML tidak tersedia'),
                'pml_email' => $type === 'ppl' ? $group->first()->pml_email : null,
                'assignments' => $group->count(),
                'target' => $target,
                'ppl' => $cumulativePpl,
                'pml' => $cumulativePml,
                'daily_ppl' => $type === 'ppl' ? $latestDaily : null,
                'daily_pml' => $type === 'pml' ? $latestDaily : null,
                'remaining' => $target - $cumulative,
                'progress_percent' => $this->ratio($cumulative, $target),
                'pml_vs_ppl_percent' => $this->ratio($cumulativePml, $cumulativePpl),
                'pml_vs_target_percent' => $this->ratio($cumulativePml, $target),
                'pending_review' => max($cumulativePpl - $cumulativePml, 0),
                'required_daily' => $required,
                'daily_deficit' => $required !== null && $latestDaily !== null ? $required - $latestDaily : null,
                'recent' => $recent,
            ];
        })->values();
    }

    private function recentSnapshots(?string $date): array
    {
        [$current] = $this->snapshots($date);
        if (! $current) {
            return [collect(), null];
        }
        $uploads = ProgressUpload::active()
            ->whereDate('snapshot_date', '<=', $current->snapshot_date)
            ->orderByDesc('snapshot_date')
            ->limit(4)
            ->get()
            ->sortBy('snapshot_date')
            ->values();
        $baseline = $uploads->count() > 3 ? $uploads->first() : null;

        return [$uploads->slice(-3)->values(), $baseline];
    }

    private function rows(int $uploadId, array $filters): Builder
    {
        $query = ProgressSnapshotRow::query()->where('upload_id', $uploadId);
        foreach (['pml' => 'pml_email', 'ppl' => 'ppl_email', 'status' => 'status_produktivitas'] as $filter => $column) {
            if (empty($filters[$filter])) {
                continue;
            }
            if ($filter === 'pml' || $filter === 'ppl') {
                $values = array_values(array_filter(array_map(fn ($value) => strtolower(trim((string) $value)), (array) $filters[$filter])));
                $query->where(function (Builder $subQuery) use ($column, $values) {
                    foreach ($values as $index => $value) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $subQuery->{$method}("LOWER(COALESCE(NULLIF($column, ''), '')) = ?", [$value]);
                    }
                });
            } else {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('ppl_email', 'like', $term)
                ->orWhere('pml_email', 'like', $term)
                ->orWhere('ppl_name', 'like', $term)
                ->orWhere('pml_name', 'like', $term)
                ->orWhere('nama_sls', 'like', $term)
                ->orWhere('kode_subsls', 'like', $term));
        }

        return $query;
    }

    private function groupWorkers(Collection $rows, string $type): Collection
    {
        return $rows->groupBy(fn (ProgressSnapshotRow $row) => $this->workerKey($row, $type));
    }

    private function workerKey(ProgressSnapshotRow $row, string $type): string
    {
        if ($type === 'ppl') {
            return strtolower(trim($row->ppl_email ?: $row->ppl_id));
        }

        return strtolower(trim($row->pml_email ?: '__unassigned'));
    }

    private function dailyByWorker(Collection $currentRows, Collection $previousRows, string $type): array
    {
        $dailyByAssignment = $this->dailyByAssignment($currentRows, $previousRows, $type);
        $daily = [];

        foreach ($currentRows as $row) {
            if (! array_key_exists($row->assignment_key, $dailyByAssignment)) {
                continue;
            }
            $worker = $this->workerKey($row, $type);
            $daily[$worker] = ($daily[$worker] ?? 0) + $dailyByAssignment[$row->assignment_key];
        }

        return $daily;
    }

    private function dailyByAssignment(Collection $currentRows, Collection $previousRows, string $type): array
    {
        if ($previousRows->isEmpty()) {
            return [];
        }
        $metric = $type === 'ppl' ? 'capaian_ppl' : 'capaian_pml';
        $previousByAssignment = $previousRows->keyBy('assignment_key');
        $previousByCode = $previousRows->groupBy('kode_subsls')->map(fn (Collection $rows) => (int) $rows->sum($metric));
        $daily = [];
        $fallback = [];

        foreach ($currentRows as $row) {
            $current = (int) ($row->{$metric} ?? 0);
            $before = $previousByAssignment->get($row->assignment_key);
            if ($before) {
                $daily[$row->assignment_key] = $current - (int) ($before->{$metric} ?? 0);
            } else {
                $fallback[$row->kode_subsls][] = $row;
            }
        }

        foreach ($fallback as $code => $rows) {
            $delta = array_sum(array_map(fn (ProgressSnapshotRow $row) => (int) ($row->{$metric} ?? 0), $rows))
                - (int) $previousByCode->get($code, 0);
            foreach ($rows as $index => $row) {
                $daily[$row->assignment_key] = $index === 0 ? $delta : 0;
            }
        }

        return $daily;
    }

    private function comparisonRows(Collection $currentRows, Collection $previousRows): Collection
    {
        if ($currentRows->isEmpty()) {
            return collect();
        }
        $keys = $currentRows->pluck('assignment_key')->all();
        $codes = $currentRows->pluck('kode_subsls')->unique()->all();

        return $previousRows->filter(fn (ProgressSnapshotRow $row) => in_array($row->assignment_key, $keys, true) || in_array($row->kode_subsls, $codes, true))->values();
    }

    private function dailyTotal(Collection $currentRows, Collection $previousRows, string $type): int
    {
        return (int) array_sum($this->dailyByWorker($currentRows, $previousRows, $type));
    }

    private function sumTotals(Collection $rows): object
    {
        return (object) [
            'target' => (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->target ?? 0)),
            'ppl' => (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_ppl ?? 0)),
            'pml' => (int) $rows->sum(fn (ProgressSnapshotRow $row) => (int) ($row->capaian_pml ?? 0)),
        ];
    }

    private function paginateWorkers(Collection $rows, string $sort, string $direction, int $page, int $perPage, string $type): array
    {
        $allowed = ['email', 'target', 'ppl', 'pml', 'daily_ppl', 'daily_pml', 'daily_deficit', 'progress_percent', 'remaining', 'pending_review'];
        $implicitSort = ! in_array($sort, $allowed, true);
        $sort = $implicitSort ? ($type === 'ppl' ? 'ppl' : 'pml') : $sort;
        $rows = $rows->sortBy(function (array $row) use ($sort) {
            $value = $row[$sort] ?? null;
            return $value === null ? PHP_INT_MAX : $value;
        }, SORT_REGULAR, $implicitSort || $direction === 'desc')->values();
        $total = $rows->count();

        return [
            'data' => $rows->forPage($page, $perPage)->values(),
                'meta' => [
                    'page' => $page, 'per_page' => $perPage, 'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                'recent_dates' => $rows->first() ? array_column($rows->first()['recent'], 'date') : [],
                ],
        ];
    }

    private function deadlinePayload(?ProgressUpload $snapshot, ?object $totals): array
    {
        $settings = $this->deadlineSettings();
        if (! $snapshot || ! $totals) {
            return ['date' => $settings?->target_date?->toDateString(), 'status' => $settings?->target_date ? 'unset_snapshot' : 'unset', 'days_remaining' => null, 'required_daily_ppl' => null, 'required_daily_pml' => null];
        }

        return $this->deadlineFor($snapshot, (int) $totals->ppl, (int) $totals->pml, (int) $totals->target, $settings);
    }

    private function deadlineFor(ProgressUpload $snapshot, int $ppl, int $pml, int $target, ?DashboardSetting $settings): array
    {
        $date = $settings?->target_date;
        $remainingPpl = max($target - $ppl, 0);
        $remainingPml = max($target - $pml, 0);
        if (! $date) {
            return ['date' => null, 'status' => 'unset', 'days_remaining' => null, 'required_daily_ppl' => null, 'required_daily_pml' => null];
        }

        $difference = (int) $snapshot->snapshot_date->diffInDays($date, false);
        $days = max($difference, 0);
        $required = fn (int $remaining) => $remaining === 0 ? 0 : (int) ceil($remaining / max($days, 1));

        return [
            'date' => $date->toDateString(),
            'status' => $difference < 0 ? 'overdue' : ($difference === 0 ? 'due' : 'active'),
            'days_remaining' => $days,
            'required_daily_ppl' => $required($remainingPpl),
            'required_daily_pml' => $required($remainingPml),
        ];
    }

    private function deadlineSettings(): ?DashboardSetting
    {
        return DashboardSetting::query()->find(1);
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
