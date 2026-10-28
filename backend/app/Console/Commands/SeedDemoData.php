<?php

namespace App\Console\Commands;

use App\Models\Amenity;
use App\Models\Conversation;
use App\Models\Favorite;
use App\Models\Institution;
use App\Models\Media;
use App\Models\Message;
use App\Models\Property;
use App\Models\Report;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Review;
use App\Models\Room;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedDemoData extends Command
{
    protected $signature = 'findcasa:seed-demo {--reset-passwords : Remplace les mots de passe des comptes de démonstration}';
    protected $description = 'Crée explicitement un scénario complet et idempotent pour la soutenance';

    public function handle(): int
    {
        [$owner, $ownerPassword] = $this->demoUser('Propriétaire Démo', 'proprietaire.demo@findcasa.bj', 'owner');
        [$student, $studentPassword] = $this->demoUser('Étudiant Démo', 'etudiant.demo@findcasa.bj', 'student');
        $owner->update(['owner_verification_status' => 'verified', 'phone' => '01 97 00 00 01']);
        $institution = Institution::where('code', 'uac')->first();
        $student->update(['institution_id' => $institution?->id, 'phone' => '01 97 00 00 02']);

        $amenities = collect(['Eau courante', 'Électricité', 'Sécurité', 'Douche interne', 'Cuisine', 'Internet', 'Parking'])
            ->mapWithKeys(fn ($name) => [$name => Amenity::firstOrCreate(['name' => $name], ['is_active' => true])->id]);

        $properties = [
            ['title' => 'Chambre salon sanitaire à Zogbadjè', 'type' => 'Chambre salon sanitaire', 'district' => 'Zogbadjè', 'address' => 'Rue du campus, Zogbadjè', 'surface' => 28, 'lat' => 6.4250, 'lng' => 2.3500, 'rent' => 35000, 'deposit' => 20000, 'advance' => 35000, 'amenities' => ['Eau courante', 'Électricité', 'Sécurité', 'Douche interne', 'Cuisine', 'Internet']],
            ['title' => 'Entrée coucher simple à Godomey', 'type' => 'Entrée coucher simple', 'district' => 'Godomey', 'address' => 'Quartier Womey, Godomey', 'surface' => 19, 'lat' => 6.3650, 'lng' => 2.3350, 'rent' => 25000, 'deposit' => 15000, 'advance' => 25000, 'amenities' => ['Eau courante', 'Électricité', 'Sécurité', 'Parking']],
            ['title' => 'Double chambre salon sanitaire à Arconville', 'type' => 'Double chambre salon sanitaire', 'district' => 'Arconville', 'address' => 'Arconville, Abomey-Calavi', 'surface' => 46, 'lat' => 6.4300, 'lng' => 2.3300, 'rent' => 45000, 'deposit' => 30000, 'advance' => 45000, 'amenities' => ['Eau courante', 'Électricité', 'Sécurité', 'Douche interne', 'Cuisine', 'Parking']],
        ];

        $created = collect($properties)->map(function ($data, $index) use ($owner, $amenities) {
            $property = Property::updateOrCreate(
                ['owner_id' => $owner->id, 'title' => $data['title']],
                [
                    'description' => 'Logement étudiant de démonstration, géolocalisé et vérifié par FindCasa.',
                    'type' => $data['type'], 'address' => $data['address'], 'district' => $data['district'], 'surface' => $data['surface'],
                    'bedroom_count' => str_starts_with($data['type'], 'Double') ? 2 : 1,
                    'latitude' => $data['lat'], 'longitude' => $data['lng'], 'publication_status' => 'published', 'origin' => 'direct',
                    'reviewed_at' => now(), 'exact_location_visible' => false,
                ],
            );
            $roomCount = str_starts_with($data['type'], 'Double') ? 2 : 1;
            foreach (range(1, $roomCount) as $roomIndex) {
                Room::updateOrCreate(
                    ['property_id' => $property->id, 'name' => 'Chambre '.$roomIndex],
                    ['rent' => $data['rent'], 'deposit' => $data['deposit'], 'advance' => $data['advance'], 'additional_fees' => 0, 'status' => 'available'],
                );
            }
            $property->amenities()->sync(collect($data['amenities'])->map(fn ($name) => $amenities[$name])->all());
            $this->attachDemoMedia($property, $index);
            return $property->fresh(['rooms', 'media']);
        });

        $first = $created->first();
        $second = $created->get(1);
        Favorite::firstOrCreate(['user_id' => $student->id, 'property_id' => $first->id]);
        $completed = Reservation::updateOrCreate(
            ['student_id' => $student->id, 'room_id' => $first->rooms->first()->id, 'start_date' => now()->subMonths(7)->toDateString()],
            ['end_date' => now()->subMonth()->toDateString(), 'duration_months' => 6, 'message' => 'Je suis intéressé par cette chambre.', 'status' => 'completed', 'confirmed_at' => now()->subMonths(7)],
        );
        ReservationStatusHistory::firstOrCreate(['reservation_id' => $completed->id, 'to_status' => 'completed'], ['changed_by' => $owner->id, 'from_status' => 'rented', 'note' => 'Scénario de démonstration']);
        Review::updateOrCreate(['student_id' => $student->id, 'reservation_id' => $completed->id], ['property_id' => $first->id, 'rating' => 5, 'comment' => 'Logement calme, propre et proche du campus.', 'status' => 'published']);
        $conversation = Conversation::firstOrCreate(['property_id' => $first->id, 'student_id' => $student->id, 'owner_id' => $owner->id]);
        Message::firstOrCreate(['conversation_id' => $conversation->id, 'sender_id' => $student->id, 'body' => 'Bonjour, la chambre est-elle toujours disponible ?']);
        Message::firstOrCreate(['conversation_id' => $conversation->id, 'sender_id' => $owner->id, 'body' => 'Bonjour, oui. Nous pouvons organiser une visite.']);
        Report::firstOrCreate(['reporter_id' => $student->id, 'property_id' => $second->id, 'reason' => 'prix_incorrect'], ['description' => 'Signalement de démonstration pour le jury.', 'status' => 'pending']);

        $this->newLine();
        $this->info('Données de soutenance prêtes.');
        $this->line('Propriétaire : '.$owner->email.($ownerPassword ? ' / '.$ownerPassword : ' / mot de passe existant'));
        $this->line('Étudiant : '.$student->email.($studentPassword ? ' / '.$studentPassword : ' / mot de passe existant'));
        $this->warn('Ces comptes sont réservés à la démonstration locale.');

        return self::SUCCESS;
    }

    private function demoUser(string $name, string $email, string $role): array
    {
        $user = User::firstOrNew(['email' => $email]);
        $password = null;
        if (!$user->exists || $this->option('reset-passwords')) {
            $password = Str::password(14, true, true, false);
            $user->password = $password;
        }
        $user->fill(['name' => $name, 'role' => $role, 'is_suspended' => false]);
        $user->email_verified_at = now();
        $user->save();
        return [$user, $password];
    }

    private function attachDemoMedia(Property $property, int $index): void
    {
        $urls = [
            'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1200&q=82',
            'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=82',
            'https://images.unsplash.com/photo-1560185008-b033106af5c3?auto=format&fit=crop&w=1200&q=82',
        ];
        foreach (['chambre', 'devanture'] as $order => $category) {
            $path = "properties/{$property->id}/demo-{$category}.jpg";
            if (!Storage::disk('public')->exists($path)) {
                $response = Http::timeout(30)->get($urls[($index + $order) % count($urls)]);
                if ($response->successful()) Storage::disk('public')->put($path, $response->body());
                else Storage::disk('public')->put($path, $this->placeholderPng(1200, 800, $index + $order));
            }
            $absolutePath = Storage::disk('public')->path($path);
            $mime = is_file($absolutePath) ? (mime_content_type($absolutePath) ?: 'image/jpeg') : 'image/jpeg';
            Media::updateOrCreate(
                ['property_id' => $property->id, 'category' => $category],
                ['kind' => 'photo', 'disk' => 'public', 'path' => $path, 'mime_type' => $mime, 'size' => Storage::disk('public')->size($path), 'is_approved' => true, 'sort_order' => $order],
            );
        }
    }

    private function placeholderPng(int $width, int $height, int $variant): string
    {
        $colors = [[31,107,90], [190,105,75], [210,157,53]];
        [$red, $green, $blue] = $colors[$variant % count($colors)];
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat(chr($red).chr($green).chr($blue), $width);
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat($row, $height), 9)).$chunk('IEND', '');
    }
}
