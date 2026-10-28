<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Notifications\FindCasaEventNotification;
use App\Models\AuditLog;
use App\Models\User;

class ReportController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->publication_status === 'published', 404);
        $data = $request->validate(['reason' => ['required', 'in:fausse_localisation,prix_incorrect,annonce_obsolete,photos_trompeuses,contenu_frauduleux'], 'description' => ['nullable', 'string', 'max:2000']]);
        $report = Report::create([...$data, 'reporter_id' => $request->user()->id, 'property_id' => $property->id]);
        User::where('role', 'admin')->where('is_suspended', false)->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification(
            'Nouveau signalement',
            "Une annonce a été signalée pour : {$data['reason']}.",
            ['report_id' => $report->id, 'property_id' => $property->id],
        )));
        return response()->json(['message' => 'Signalement envoyé.', 'data' => $report], 201);
    }

    public function pending(): JsonResponse { return response()->json(['data' => Report::where('status', 'pending')->with(['property', 'reporter'])->latest()->paginate(30)]); }

    public function review(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:investigating,resolved,rejected'], 'admin_note' => ['nullable', 'string', 'max:2000'], 'suspend_property' => ['sometimes', 'boolean']]);
        if ($data['suspend_property'] ?? false) $report->property()->update(['publication_status' => 'rejected', 'moderation_note' => $data['admin_note'] ?? 'Annonce suspendue après signalement.']);
        unset($data['suspend_property']);
        $report->update($data);
        AuditLog::record($request, 'report.reviewed.'.$data['status'], $report, ['property_id' => $report->property_id]);
        $report->reporter->notify(new FindCasaEventNotification(
            'Signalement traité',
            'L’administration a traité ton signalement.',
            ['report_id' => $report->id, 'property_id' => $report->property_id],
        ));
        return response()->json(['message' => 'Signalement traité.', 'data' => $report->fresh()]);
    }
}
