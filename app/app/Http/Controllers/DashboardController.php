<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(): View
    {
        return view('dashboard');
    }

    public function health(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['status' => 'ok']);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->summary($request->string('date')->toString() ?: null, $this->activeFilters($request)));
    }

    public function timeSeries(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->timeSeries($this->activeFilters($request), $request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null)]);
    }

    public function ppl(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->ppl(
            $request->string('date')->toString() ?: null,
            $this->activeFilters($request),
            $request->string('sort')->toString(),
            $request->string('direction')->toString() ?: 'desc',
            max(1, $request->integer('page', 1)),
            min(100, max(10, $request->integer('per_page', 25))),
        ));
    }

    public function pml(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->pml(
            $request->string('date')->toString() ?: null,
            $this->activeFilters($request),
            $request->string('sort')->toString(),
            $request->string('direction')->toString() ?: 'desc',
            max(1, $request->integer('page', 1)),
            min(100, max(10, $request->integer('per_page', 25))),
        ));
    }

    public function breakdown(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['ppl', 'pml'])],
            'worker' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->dashboard->breakdown(
            $request->string('date')->toString() ?: null,
            $this->activeFilters($request),
            $data['type'],
            $data['worker'],
        ));
    }

    public function filters(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->filters($request->string('date')->toString() ?: null));
    }

    private function activeFilters(Request $request): array
    {
        return $request->only(['pml', 'ppl', 'status', 'jenis_mitra', 'search']);
    }
}
