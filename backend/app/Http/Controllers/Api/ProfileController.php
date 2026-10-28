<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->load(['institution', 'campus'])]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['nullable', 'digits:10'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        if (filled($data['password'] ?? null) && !Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }

        $user->update([
            'name' => $data['name'] ?? $user->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
            'institution_id' => $user->role === 'student' && array_key_exists('institution_id', $data) ? $data['institution_id'] : $user->institution_id,
            ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : []),
        ]);

        if (filled($data['password'] ?? null)) $user->tokens()->where('id', '<>', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Profil mis à jour.', 'data' => $user->fresh()->load(['institution', 'campus'])]);
    }
}
