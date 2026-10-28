<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Conversation;
use App\Models\ExternalContactRequest;
use App\Models\ReservationStatusHistory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Notifications\FindCasaEventNotification;

class ReservationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:36'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $reservation = DB::transaction(function () use ($data, $request) {
            $room = Room::query()->lockForUpdate()->findOrFail($data['room_id']);
            $room->loadMissing('property');
            if ($room->property->publication_status !== 'published') {
                throw ValidationException::withMessages(['room_id' => 'Cette chambre ne dépend pas d’une annonce publiée.']);
            }
            if ($room->property->owner_id === null) {
                return ExternalContactRequest::create([
                    'student_id' => $request->user()->id,
                    'property_id' => $room->property_id,
                    'start_date' => $data['start_date'],
                    'duration_months' => $data['duration_months'],
                    'message' => $data['message'] ?? null,
                    'status' => 'pending',
                ])->load('property');
            }
            if ($room->status !== 'available') {
                throw ValidationException::withMessages(['room_id' => 'Cette chambre n’est plus disponible.']);
            }

            $start = Carbon::parse($data['start_date'])->startOfDay();
            $end = $start->copy()->addMonths($data['duration_months']);

            $conflict = Reservation::query()
                ->where('room_id', $room->id)
                ->whereIn('status', ['pending', 'accepted', 'confirmed'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->where('start_date', '<', $end->toDateString())
                ->where('end_date', '>', $start->toDateString())
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['room_id' => 'Cette chambre est déjà demandée sur cette période.']);
            }

            $created = Reservation::create([
                'student_id' => $request->user()->id,
                'room_id' => $room->id,
                'start_date' => $start,
                'end_date' => $end,
                'duration_months' => $data['duration_months'],
                'message' => $data['message'] ?? null,
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);
            $this->recordHistory($created, null, 'pending', $request->user()->id, 'Demande créée');
            return $created->load(['room.property']);
        });

        if ($reservation instanceof ExternalContactRequest) {
            return response()->json(['message' => 'Le propriétaire externe doit être contacté avant la réservation. Ta demande a été transmise à l’administration.', 'data' => $reservation, 'requires_external_confirmation' => true], 202);
        }
        $reservation->room->property->owner?->notify(new FindCasaEventNotification('Nouvelle demande', 'Un étudiant a envoyé une demande de réservation.', ['reservation_id' => $reservation->id]));

        return response()->json(['message' => 'Demande de réservation envoyée.', 'data' => $reservation], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $reservations = Reservation::query()->where('student_id', $request->user()->id)->with(['room.property', 'history'])->latest()->get();
        $reservations->each(function (Reservation $reservation) {
            if (!in_array($reservation->status, ['accepted', 'confirmed', 'rented', 'completed'], true)) {
                $reservation->room?->property?->makeHidden(['address', 'latitude', 'longitude']);
            }
        });
        return response()->json(['data' => $reservations]);
    }

    public function received(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Reservation::query()->whereHas('room.property', fn ($query) => $query->where('owner_id', $request->user()->id))->with(['student:id,name,email,phone', 'room.property', 'history'])->latest()->get(),
        ]);
    }

    public function respond(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->room->property->owner_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
            'owner_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($data, $reservation, $request) {
            $reservation = Reservation::query()->lockForUpdate()->with('room.property')->findOrFail($reservation->id);
            if ($reservation->status !== 'pending' || ($reservation->expires_at && $reservation->expires_at->isPast())) {
                throw ValidationException::withMessages(['status' => 'Cette demande a déjà été traitée.']);
            }

            if ($data['status'] === 'accepted') {
                $conflict = Reservation::query()
                    ->where('room_id', $reservation->room_id)
                    ->where('id', '<>', $reservation->id)
                    ->whereIn('status', ['accepted', 'confirmed'])
                    ->where('start_date', '<', $reservation->end_date)
                    ->where('end_date', '>', $reservation->start_date)
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages(['status' => 'Une autre réservation couvre déjà cette période.']);
                }
            }

            $reservation->update([
                'status' => $data['status'],
                'owner_message' => $data['owner_message'] ?? null,
                'expires_at' => $data['status'] === 'accepted' ? now()->addDays(3) : null,
            ]);
            $this->recordHistory($reservation, 'pending', $data['status'], $request->user()->id, $data['owner_message'] ?? null);

            $conversation = null;
            if ($data['status'] === 'accepted') {
                $conversation = Conversation::firstOrCreate([
                    'property_id' => $reservation->room->property_id,
                    'student_id' => $reservation->student_id,
                    'owner_id' => $request->user()->id,
                ]);
                if ($reservation->message && !$conversation->messages()->exists()) {
                    $conversation->messages()->create(['sender_id' => $reservation->student_id, 'body' => $reservation->message]);
                }
            }

            return [
                'reservation' => $reservation->fresh(['student', 'room.property']),
                'conversation_id' => $conversation?->id,
            ];
        });

        $updated = $result['reservation'];
        $updated->student->notify(new FindCasaEventNotification(
            $updated->status === 'accepted' ? 'Demande acceptée' : 'Demande refusée',
            $updated->status === 'accepted' ? 'Le propriétaire a accepté ta demande. Tu peux maintenant échanger avec lui.' : 'Le propriétaire a refusé ta demande de réservation.',
            ['reservation_id' => $updated->id, 'conversation_id' => $result['conversation_id']],
        ));

        return response()->json(['message' => 'Réponse enregistrée.', 'data' => $updated, 'conversation_id' => $result['conversation_id']]);
    }

    public function confirm(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->student_id === $request->user()->id, 403);

        $conversationId = DB::transaction(function () use ($reservation, $request) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== 'accepted' || ($locked->expires_at && $locked->expires_at->isPast())) {
                throw ValidationException::withMessages(['status' => 'Cette demande ne peut plus être confirmée.']);
            }
            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $locked->room()->update(['status' => 'reserved']);
            $this->recordHistory($locked, 'accepted', 'confirmed', $request->user()->id, 'Confirmation étudiant');
            return Conversation::query()
                ->where('property_id', $locked->room->property_id)
                ->where('student_id', $locked->student_id)
                ->where('owner_id', $locked->room->property->owner_id)
                ->value('id');
        });

        return response()->json(['message' => 'Réservation confirmée.', 'conversation_id' => $conversationId]);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->student_id === $request->user()->id, 403);
        if (!in_array($reservation->status, ['pending', 'accepted'], true)) return response()->json(['message' => 'Cette réservation ne peut plus être annulée.'], 422);
        $from = $reservation->status;
        $reservation->update(['status' => 'cancelled', 'expires_at' => null]);
        $this->recordHistory($reservation, $from, 'cancelled', $request->user()->id, 'Annulation étudiant');
        return response()->json(['message' => 'Demande annulée.', 'data' => $reservation->fresh('history')]);
    }

    public function markRented(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->room->property->owner_id === $request->user()->id, 403);
        if ($reservation->status !== 'confirmed') return response()->json(['message' => 'La réservation doit être confirmée avant l’installation.'], 422);
        DB::transaction(function () use ($reservation, $request) {
            $reservation->update(['status' => 'rented']);
            $reservation->room()->update(['status' => 'rented']);
            $this->recordHistory($reservation, 'confirmed', 'rented', $request->user()->id, 'Installation effectuée');
        });
        $reservation->student->notify(new FindCasaEventNotification('Location démarrée', 'Le propriétaire a confirmé ton installation.', ['reservation_id' => $reservation->id]));
        return response()->json(['message' => 'Location démarrée.', 'data' => $reservation->fresh(['room.property', 'history'])]);
    }

    public function complete(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->room->property->owner_id === $request->user()->id, 403);
        if ($reservation->status !== 'rented') return response()->json(['message' => 'Seule une location en cours peut être terminée.'], 422);
        DB::transaction(function () use ($reservation, $request) {
            $reservation->update(['status' => 'completed']);
            $reservation->room()->update(['status' => 'available']);
            $this->recordHistory($reservation, 'rented', 'completed', $request->user()->id, 'Location terminée');
        });
        $reservation->student->notify(new FindCasaEventNotification('Location terminée', 'Tu peux maintenant laisser un avis sur le logement.', ['reservation_id' => $reservation->id]));
        return response()->json(['message' => 'Location terminée et chambre libérée.', 'data' => $reservation->fresh(['room.property', 'history'])]);
    }

    public function externalMine(Request $request): JsonResponse
    {
        return response()->json(['data' => ExternalContactRequest::where('student_id', $request->user()->id)->with('property')->latest()->get()]);
    }

    public function externalPending(): JsonResponse
    {
        return response()->json(['data' => ExternalContactRequest::where('status', 'pending')->with(['student:id,name,email,phone', 'property.externalSource'])->latest()->paginate(30)]);
    }

    private function recordHistory(Reservation $reservation, ?string $from, string $to, ?int $actor, ?string $note = null): void
    {
        ReservationStatusHistory::create(['reservation_id' => $reservation->id, 'changed_by' => $actor, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }
}
