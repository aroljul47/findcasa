<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\AuditLog;

class InstitutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $institutions = Institution::query()
            ->where('is_active', true)
            ->with(['campuses' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $institutions]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', 'unique:institutions,code'],
            'category' => ['required', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $institution = Institution::create($data);
        AuditLog::record($request, 'institution.created', $institution);
        return response()->json(['data' => $institution], 201);
    }

    public function update(Request $request, Institution $institution): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('institutions', 'code')->ignore($institution)],
            'category' => ['sometimes', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $institution->update($data);
        AuditLog::record($request, 'institution.updated', $institution, ['changes' => array_keys($data)]);

        return response()->json(['data' => $institution->fresh('campuses')]);
    }

    public function destroy(Request $request, Institution $institution): JsonResponse
    {
        $institution->update(['is_active' => false]);
        AuditLog::record($request, 'institution.disabled', $institution);

        return response()->json(['message' => 'Établissement désactivé.']);
    }
}
