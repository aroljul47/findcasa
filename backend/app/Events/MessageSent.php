<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message, public int $recipientId)
    {
        $this->message->loadMissing('sender:id,name');
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->only(['id', 'conversation_id', 'sender_id', 'body', 'read_at', 'created_at']),
            'sender' => $this->message->sender?->only(['id', 'name']),
        ];
    }
}
