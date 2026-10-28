<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\FindCasaEventNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\'\-]+$/u'],
            'last_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\'\-]+$/u'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'digits:10'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'role' => ['required', 'in:student,owner'],
            'institution_id' => ['required_if:role,student', 'nullable', 'integer', 'exists:institutions,id'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'identity_document' => ['required_if:role,owner', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required' => 'Le nom est obligatoire.',
            'first_name.regex' => 'Le prénom contient des caractères invalides.',
            'last_name.regex' => 'Le nom contient des caractères invalides.',
            'email.required' => 'L’adresse email est obligatoire.',
            'email.email' => 'L’adresse email n’est pas valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.digits' => 'Le numéro de téléphone doit contenir exactement 10 chiffres.',
            'institution_id.required_if' => 'Choisis ton établissement.',
            'institution_id.exists' => 'L’établissement sélectionné est invalide.',
            'identity_document.required_if' => 'La pièce d’identité est obligatoire pour un propriétaire.',
            'identity_document.mimes' => 'La pièce d’identité doit être une image JPG, PNG ou un PDF.',
            'identity_document.max' => 'La pièce d’identité ne doit pas dépasser 10 Mo.',
        ]);

        $identityPath = $request->hasFile('identity_document')
            ? $request->file('identity_document')->store('identity-documents', 'local')
            : null;

        $user = User::create([
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'role' => $validated['role'],
            'institution_id' => $validated['role'] === 'student' ? $validated['institution_id'] : null,
            'campus_id' => null,
            'owner_verification_status' => $validated['role'] === 'owner' ? 'pending' : 'not_applicable',
            'identity_document_path' => $identityPath,
        ]);
        $user->sendEmailVerificationNotification();
        if ($user->role === 'owner') {
            User::where('role', 'admin')->where('is_suspended', false)->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification(
                'Nouveau propriétaire à vérifier',
                "{$user->name} a soumis une demande de vérification propriétaire.",
                ['owner_id' => $user->id],
            )));
        }

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => $user->load(['institution', 'campus']),
            'token' => $user->createToken('findcasa')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'Adresse ou mot de passe incorrect.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        if ($user->is_suspended) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Ce compte est actuellement suspendu.',
            ]);
        }

        return response()->json([
            'message' => 'Connexion réussie.',
            'user' => $user->load(['institution', 'campus']),
            'token' => $user->createToken('findcasa')->plainTextToken,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load(['institution', 'campus']));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }
}
