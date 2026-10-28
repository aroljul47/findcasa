<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function statistics(): JsonResponse
    {
        $propertyTotal = Property::count();
        $collectedTotal = Property::where('origin', 'collected')->count();
        return response()->json(['data' => [
            'users' => User::count(),
            'students' => User::where('role', 'student')->count(),
            'owners' => User::where('role', 'owner')->count(),
            'verified_owners' => User::where('role', 'owner')->where('owner_verification_status', 'verified')->count(),
            'suspended_users' => User::where('is_suspended', true)->count(),
            'properties' => $propertyTotal,
            'published_properties' => Property::where('publication_status', 'published')->count(),
            'pending_properties' => Property::where('publication_status', 'pending')->count(),
            'available_rooms' => Room::where('status', 'available')->count(),
            'reservations' => Reservation::count(),
            'active_rentals' => Reservation::where('status', 'rented')->count(),
            'average_rent' => round((float) (Room::avg('rent') ?? 0)),
            'collected_share' => $propertyTotal ? round($collectedTotal * 100 / $propertyTotal, 1) : 0,
            'average_rent_by_district' => Room::query()->join('properties', 'properties.id', '=', 'rooms.property_id')->whereNotNull('properties.district')->groupBy('properties.district')->orderBy('properties.district')->get(['properties.district', DB::raw('ROUND(AVG(rooms.rent)) as average_rent')]),
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'role' => ['nullable', 'in:student,owner,admin'], 'status' => ['nullable', 'in:active,suspended']]);
        return response()->json(User::query()
            ->with(['institution:id,name', 'campus:id,name'])
            ->when($data['q'] ?? null, fn ($query, $q) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->when($data['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when(($data['status'] ?? null) === 'active', fn ($query) => $query->where('is_suspended', false))
            ->when(($data['status'] ?? null) === 'suspended', fn ($query) => $query->where('is_suspended', true))
            ->latest()->paginate(30));
    }

    public function institutions(): JsonResponse
    {
        return response()->json(['data' => Institution::with(['campuses' => fn ($query) => $query->orderBy('name')])->orderBy('category')->orderBy('name')->get()]);
    }

    public function logs(): JsonResponse
    {
        return response()->json(AuditLog::with('actor:id,name,email')->latest()->paginate(50));
    }
}
