<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse { return response()->json(['data' => $request->user()->notifications()->latest()->paginate(30)]); }
    public function read(Request $request, string $notification): JsonResponse { $item = $request->user()->notifications()->whereKey($notification)->firstOrFail(); $item->markAsRead(); return response()->json(['message' => 'Notification lue.']); }
    public function readAll(Request $request): JsonResponse { $request->user()->unreadNotifications->markAsRead(); return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues.']); }
}
