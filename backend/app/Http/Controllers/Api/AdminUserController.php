<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Notifications\FindCasaEventNotification;
use App\Models\AuditLog;

class AdminUserController extends Controller
{
    public function pendingOwners(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->where('role', 'owner')->where('owner_verification_status', 'pending')->latest()->get(['id', 'name', 'email', 'phone', 'identity_document_path', 'created_at'])->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'has_identity_document' => filled($user->identity_document_path),
                'created_at' => $user->created_at,
            ]),
        ]);
    }

    public function verifyOwner(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'owner', 404);

        $data = $request->validate(['status' => ['required', 'in:verified,rejected']]);
        $user->update(['owner_verification_status' => $data['status']]);
        AuditLog::record($request, 'owner.verification.'.$data['status'], $user);
        $user->notify(new FindCasaEventNotification(
            $data['status'] === 'verified' ? 'Compte propriétaire vérifié' : 'Vérification refusée',
            $data['status'] === 'verified' ? 'Ton compte propriétaire est maintenant vérifié.' : 'Ta demande de vérification propriétaire a été refusée.',
        ));

        return response()->json(['message' => 'Vérification du propriétaire mise à jour.', 'data' => $user->fresh()]);
    }

    public function identityDocument(User $user): StreamedResponse
    {
        abort_unless($user->role === 'owner' && filled($user->identity_document_path), 404);
        abort_unless(Storage::disk('local')->exists($user->identity_document_path), 404);

        return Storage::disk('local')->download($user->identity_document_path);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Tu ne peux pas suspendre ton propre compte administrateur.');
        $data = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $user->update(['is_suspended' => $data['suspended']]);
        if ($data['suspended']) $user->tokens()->delete();
        AuditLog::record($request, $data['suspended'] ? 'user.suspended' : 'user.reactivated', $user, ['reason' => $data['reason'] ?? null]);
        $user->notify(new FindCasaEventNotification($data['suspended'] ? 'Compte suspendu' : 'Compte réactivé', $data['suspended'] ? 'Ton compte FindCasa a été suspendu.' : 'Ton compte FindCasa a été réactivé.'));
        return response()->json(['message' => $data['suspended'] ? 'Utilisateur suspendu.' : 'Utilisateur réactivé.', 'data' => $user->fresh(['institution', 'campus'])]);
    }
}
