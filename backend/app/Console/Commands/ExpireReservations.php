<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;
use App\Notifications\FindCasaEventNotification;

class ExpireReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'findcasa:expire-reservations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire les demandes et confirmations arrivées à échéance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $reservations = Reservation::query()
            ->whereIn('status', ['pending', 'accepted'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with(['student', 'room.property.owner'])
            ->get();

        foreach ($reservations as $reservation) {
            $from = $reservation->status;
            $reservation->update(['status' => 'expired']);
            $reservation->history()->create(['changed_by' => null, 'from_status' => $from, 'to_status' => 'expired', 'note' => 'Expiration automatique']);
            $reservation->student?->notify(new FindCasaEventNotification('Demande expirée', 'Ta demande de réservation a expiré.', ['reservation_id' => $reservation->id]));
            $reservation->room?->property?->owner?->notify(new FindCasaEventNotification('Demande expirée', 'Une demande de réservation a expiré.', ['reservation_id' => $reservation->id]));
        }

        $this->info($reservations->count().' réservation(s) expirée(s).');
        return self::SUCCESS;
    }
}
