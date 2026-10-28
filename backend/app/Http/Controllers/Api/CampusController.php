<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\AuditLog;

class CampusController extends Controller
{
    public function store(Request $request, Institution $institution): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $campus = $institution->campuses()->create($data);
        AuditLog::record($request, 'campus.created', $campus, ['institution_id' => $institution->id]);

        return response()->json(['data' => $campus], 201);
    }

    public function update(Request $request, Campus $campus): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $campus->update($data);
        AuditLog::record($request, 'campus.updated', $campus, ['changes' => array_keys($data)]);

        return response()->json(['data' => $campus->fresh()]);
    }

    public function destroy(Request $request, Campus $campus): JsonResponse
    {
        $campus->update(['is_active' => false]);
        AuditLog::record($request, 'campus.disabled', $campus);

        return response()->json(['message' => 'Campus désactivé.']);
    }
}
