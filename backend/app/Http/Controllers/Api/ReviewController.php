<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Notifications\FindCasaEventNotification;

class ReviewController extends Controller
{
    public function index(int $property): JsonResponse
    {
        $query = Review::where('property_id', $property)->where('status', 'published');
        return response()->json(['data' => [
            'reviews' => (clone $query)->with('student:id,name')->latest()->get(),
            'average_rating' => round((float) ((clone $query)->avg('rating') ?? 0), 1),
            'reviews_count' => (clone $query)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => ['required', 'integer', 'exists:reservations,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $reservation = Reservation::with('room')->findOrFail($data['reservation_id']);
        abort_unless($reservation->student_id === $request->user()->id && $reservation->status === 'completed', 403);

        $review = Review::create([...$data, 'student_id' => $request->user()->id, 'property_id' => $reservation->room->property_id]);
        return response()->json(['message' => 'Avis soumis à modération.', 'data' => $review], 201);
    }

    public function pending(): JsonResponse
    {
        return response()->json(['data' => Review::where('status', 'pending')->with(['student:id,name', 'property:id,title'])->latest()->paginate(30)]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Review::where('student_id', $request->user()->id)
                ->with(['property:id,title,district', 'reservation:id,start_date,end_date,status'])
                ->latest()
                ->get(),
        ]);
    }

    public function review(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:published,rejected']]);
        $review->update($data);
        $review->student->notify(new FindCasaEventNotification(
            $data['status'] === 'published' ? 'Avis publié' : 'Avis rejeté',
            $data['status'] === 'published' ? 'Ton avis est maintenant visible sur la fiche du logement.' : 'Ton avis a été rejeté par la modération.',
            ['review_id' => $review->id, 'property_id' => $review->property_id],
        ));
        return response()->json(['message' => 'Avis modéré.', 'data' => $review->fresh()]);
    }
}
