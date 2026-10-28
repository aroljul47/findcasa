<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Notifications\FindCasaEventNotification;
use App\Events\MessageSent;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Conversation::query()
            ->where(fn ($q) => $q->where('student_id', $request->user()->id)->orWhere('owner_id', $request->user()->id))
            ->with(['property:id,title,district', 'student:id,name', 'owner:id,name', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_messages_count' => fn ($query) => $query->where('sender_id', '<>', $request->user()->id)->whereNull('read_at')])
            ->latest()->get()]);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        abort_unless($property->publication_status === 'published', 404);
        abort_unless($request->user()->role === 'student', 403);
        abort_if($property->owner_id === null, 422, 'Cette offre externe nécessite une mise en relation par FindCasa.');
        $conversation = Conversation::firstOrCreate(['property_id' => $property->id, 'student_id' => $request->user()->id, 'owner_id' => $property->owner_id]);
        $message = $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $data['body']]);
        broadcast(new MessageSent($message, $property->owner_id))->toOthers();
        $property->owner->notify(new FindCasaEventNotification('Nouveau message', 'Un étudiant t’a envoyé un message.', ['conversation_id' => $conversation->id]));
        return response()->json(['data' => $message->load('sender')], 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless(in_array($request->user()->id, [$conversation->student_id, $conversation->owner_id], true), 403);
        $conversation->messages()->where('sender_id', '<>', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['data' => $conversation->messages()->with('sender:id,name')->oldest()->get()]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless(in_array($request->user()->id, [$conversation->student_id, $conversation->owner_id], true), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $data['body']]);
        $recipient = $request->user()->id === $conversation->student_id ? $conversation->owner : $conversation->student;
        broadcast(new MessageSent($message, $recipient->id))->toOthers();
        $recipient->notify(new FindCasaEventNotification('Nouveau message', 'Tu as reçu un nouveau message.', ['conversation_id' => $conversation->id]));
        return response()->json(['data' => $message->load('sender')], 201);
    }
}
