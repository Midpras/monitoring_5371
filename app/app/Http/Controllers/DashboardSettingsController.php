<?php

namespace App\Http\Controllers;

use App\Models\DashboardSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(DashboardSetting::query()->find(1)));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['target_date' => ['nullable', 'date']]);
        $setting = DashboardSetting::query()->find(1) ?? new DashboardSetting(['id' => 1]);
        $setting->target_date = $data['target_date'] ?? null;
        $setting->save();

        return response()->json($this->payload($setting->fresh()));
    }

    private function payload(?DashboardSetting $setting): array
    {
        return ['target_date' => $setting?->target_date?->toDateString()];
    }
}
