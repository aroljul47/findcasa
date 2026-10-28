<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Institution;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Models\Media;
use App\Notifications\FindCasaResetPasswordNotification;
use App\Notifications\FindCasaEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use App\Notifications\FindCasaVerifyEmailNotification;
use App\Models\ExternalSource;
use App\Models\CollectedOffer;
use App\Models\Amenity;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FindCasaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_is_empty_when_no_property_has_been_published(): void
    {
        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_student_registration_requires_a_matching_campus(): void
    {
        $institution = Institution::create([
            'name' => 'Université de test',
            'code' => 'test-u',
            'category' => 'Universités publiques',
        ]);
        $campus = Campus::create(['institution_id' => $institution->id, 'name' => 'Campus test']);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Aïcha',
            'last_name' => 'Kora',
            'email' => 'student@example.com',
            'phone' => '0157000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'institution_id' => $institution->id,
            'campus_id' => $campus->id,
        ])->assertCreated()->assertJsonPath('user.role', 'student');
    }

    public function test_owner_registration_stores_private_document_and_queues_verification_email(): void
    {
        Notification::fake();
        Storage::fake('local');

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Propriétaire',
            'last_name' => 'Rapide',
            'email' => 'owner-fast@example.com',
            'phone' => '0155000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'owner',
            'identity_document' => UploadedFile::fake()->create('identite.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('user.owner_verification_status', 'pending');

        $owner = User::findOrFail($response->json('user.id'));
        $this->assertNotNull($owner->identity_document_path);
        Storage::disk('local')->assertExists($owner->identity_document_path);
        Notification::assertSentTo($owner, FindCasaVerifyEmailNotification::class);
    }

    public function test_non_admin_cannot_create_an_institution(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->postJson('/api/admin/institutions', [
                'name' => 'Institution interdite',
                'category' => 'Établissements privés indépendants',
            ])
            ->assertForbidden();
    }

    public function test_overlapping_reservation_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Logement de test',
            'description' => 'Description de test',
            'type' => 'Studio',
            'address' => 'Adresse test',
            'district' => 'Godomey',
            'surface' => 30,
            'bedroom_count' => 1,
            'latitude' => 6.4,
            'longitude' => 2.3,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 100000, 'status' => 'available']);

        $payload = ['room_id' => $room->id, 'start_date' => Carbon::now()->addDays(10)->toDateString(), 'duration_months' => 6];
        $this->actingAs($student)->postJson('/api/reservations', $payload)->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->postJson('/api/reservations', $payload)
            ->assertStatus(422);
    }

    public function test_password_reset_request_sends_a_findcasa_notification_and_resets_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com', 'password' => 'old-password']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Si ce compte existe, un lien de réinitialisation a été envoyé.');

        Notification::assertSentTo($user, FindCasaResetPasswordNotification::class);

        $token = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_email_verification_marks_user_as_verified(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['role' => 'student']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertRedirect('http://localhost:5173/connexion?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $unverified = User::factory()->unverified()->create(['role' => 'student']);
        $this->actingAs($unverified)->postJson('/api/auth/email/verification-notification')->assertOk();
        Notification::assertSentTo($unverified, FindCasaVerifyEmailNotification::class);
    }

    public function test_public_catalogue_hides_exact_location(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Logement privé',
            'description' => 'Description',
            'type' => 'Chambre salon simple',
            'address' => 'Adresse exacte privée',
            'district' => 'Godomey',
            'surface' => 20,
            'bedroom_count' => 1,
            'latitude' => 6.4485,
            'longitude' => 2.3557,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);
        Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 45000, 'status' => 'available']);

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonMissing(['address' => 'Adresse exacte privée'])
            ->assertJsonMissingPath('data.0.latitude')
            ->assertJsonMissingPath('data.0.longitude');
    }

    public function test_media_model_can_be_loaded_and_serialized(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Logement média',
            'description' => 'Description',
            'type' => 'Chambre salon simple',
            'district' => 'Godomey',
            'bedroom_count' => 1,
            'publication_status' => 'pending',
            'origin' => 'direct',
        ]);
        $media = Media::create([
            'property_id' => $property->id,
            'kind' => 'photo',
            'category' => 'chambre',
            'disk' => 'public',
            'path' => 'properties/test/photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
        ]);

        $this->assertSame('photo', $media->fresh()->kind);
        $this->assertStringContainsString('/storage/properties/test/photo.jpg', $media->fresh()->url);
    }

    public function test_public_property_returns_all_approved_media_and_hides_pending_media(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Galerie publique',
            'description' => 'Description',
            'type' => 'Chambre salon sanitaire',
            'district' => 'Calavi',
            'bedroom_count' => 1,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);
        Room::create(['property_id' => $property->id, 'name' => 'Chambre', 'rent' => 30000, 'status' => 'available']);
        Media::create(['property_id' => $property->id, 'kind' => 'photo', 'category' => 'chambre', 'disk' => 'public', 'path' => 'approved-room.jpg', 'mime_type' => 'image/jpeg', 'is_approved' => true, 'sort_order' => 0]);
        Media::create(['property_id' => $property->id, 'kind' => 'photo', 'category' => 'devanture', 'disk' => 'public', 'path' => 'approved-front.jpg', 'mime_type' => 'image/jpeg', 'is_approved' => true, 'sort_order' => 1]);
        Media::create(['property_id' => $property->id, 'kind' => 'video', 'category' => 'autre', 'disk' => 'public', 'path' => 'visit.mp4', 'mime_type' => 'video/mp4', 'duration_seconds' => 180, 'is_approved' => true, 'sort_order' => 2]);
        Media::create(['property_id' => $property->id, 'kind' => 'photo', 'category' => 'cour', 'disk' => 'public', 'path' => 'pending-yard.jpg', 'mime_type' => 'image/jpeg', 'is_approved' => false, 'sort_order' => 3]);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.media')
            ->assertJsonFragment(['path' => 'approved-room.jpg'])
            ->assertJsonFragment(['path' => 'approved-front.jpg'])
            ->assertJsonFragment(['path' => 'visit.mp4', 'duration_seconds' => 180])
            ->assertJsonMissing(['path' => 'pending-yard.jpg']);
    }

    public function test_owner_can_upload_valid_photo_and_student_cannot(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified', 'email_verified_at' => now()]);
        $student = User::factory()->create(['role' => 'student', 'email_verified_at' => now()]);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Média propriétaire', 'type' => 'Chambre salon simple',
            'bedroom_count' => 1, 'publication_status' => 'pending', 'origin' => 'direct',
        ]);
        $file = $this->fakePng('chambre.png', 800, 600);

        $response = $this->actingAs($owner)->postJson("/api/owner/properties/{$property->id}/media", [
            'file' => $file, 'category' => 'chambre',
        ])->assertCreated()->assertJsonPath('data.kind', 'photo')->assertJsonPath('data.is_approved', false);

        Storage::disk('public')->assertExists($response->json('data.path'));
        $this->actingAs($student)->postJson("/api/owner/properties/{$property->id}/media", [
            'file' => $this->fakePng('interdit.png', 800, 600), 'category' => 'devanture',
        ])->assertForbidden();
    }

    public function test_admin_cannot_publish_property_without_required_approved_media(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Annonce sans devanture', 'type' => 'Chambre salon simple',
            'bedroom_count' => 1, 'publication_status' => 'pending', 'origin' => 'direct',
        ]);
        Media::create(['property_id' => $property->id, 'kind' => 'photo', 'category' => 'chambre', 'disk' => 'public', 'path' => 'room.jpg', 'mime_type' => 'image/jpeg', 'is_approved' => true]);

        $this->actingAs($admin)->patchJson("/api/admin/properties/{$property->id}/review", ['status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Une photo approuvée de la chambre et une photo approuvée de la devanture sont obligatoires.');
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::factory()->create(['role' => 'student', 'name' => 'Ancien nom', 'phone' => null]);

        $this->actingAs($user)
            ->patchJson('/api/profile', ['name' => 'Nouveau nom', 'phone' => '0157000000'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom')
            ->assertJsonPath('data.phone', '0157000000');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nouveau nom']);
    }

    public function test_student_can_list_own_reviews(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->getJson('/api/reviews/mine')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_student_cannot_reserve_room_from_unpublished_property(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Annonce en attente',
            'description' => 'Description',
            'type' => 'Chambre salon simple',
            'district' => 'Godomey',
            'bedroom_count' => 1,
            'publication_status' => 'pending',
            'origin' => 'direct',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 40000, 'status' => 'available']);

        $this->actingAs($student)->postJson('/api/reservations', [
            'room_id' => $room->id,
            'start_date' => now()->addWeek()->toDateString(),
            'duration_months' => 6,
        ])->assertUnprocessable();
    }

    public function test_suspended_user_cannot_use_protected_routes(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_suspended' => true]);

        $this->actingAs($student)->getJson('/api/favorites')->assertForbidden();
    }

    public function test_catalogue_filters_by_search_and_type(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Chambre calme à Godomey',
            'description' => 'Description',
            'type' => 'Chambre salon sanitaire',
            'district' => 'Godomey',
            'bedroom_count' => 1,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);
        Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 35000, 'status' => 'available']);

        $this->getJson('/api/properties?q=Godomey&type='.urlencode('Chambre salon sanitaire'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Chambre calme à Godomey');
    }

    public function test_catalogue_filters_by_district_amenities_and_page_size(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $internet = Amenity::create(['name' => 'Internet', 'is_active' => true]);
        $security = Amenity::create(['name' => 'Sécurité', 'is_active' => true]);
        $matching = Property::create([
            'owner_id' => $owner->id, 'title' => 'Logement équipé', 'description' => 'Description',
            'type' => 'Chambre salon sanitaire', 'district' => 'Zogbadjè', 'bedroom_count' => 1,
            'publication_status' => 'published', 'origin' => 'direct',
        ]);
        $matching->amenities()->attach([$internet->id, $security->id]);
        Room::create(['property_id' => $matching->id, 'name' => 'Chambre 1', 'rent' => 30000, 'status' => 'available']);
        $other = Property::create([
            'owner_id' => $owner->id, 'title' => 'Autre logement', 'description' => 'Description',
            'type' => 'Chambre salon simple', 'district' => 'Godomey', 'bedroom_count' => 1,
            'publication_status' => 'published', 'origin' => 'direct',
        ]);
        $other->amenities()->attach([$security->id]);
        Room::create(['property_id' => $other->id, 'name' => 'Chambre 1', 'rent' => 25000, 'status' => 'available']);

        $this->getJson('/api/properties?district=Zogbadj%C3%A8&amenity_ids[]='.$internet->id.'&amenity_ids[]='.$security->id.'&per_page=6')
            ->assertOk()
            ->assertJsonPath('per_page', 6)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matching->id);

        $this->getJson('/api/property-filters')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Internet'])
            ->assertJsonFragment(['name' => 'Sécurité']);
    }

    public function test_sitemap_collection_stages_only_allowed_available_urls(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $institution = Institution::create(['name' => 'Université collecte', 'code' => 'collect-u', 'category' => 'Universités publiques']);
        Campus::create(['institution_id' => $institution->id, 'name' => 'Campus Calavi', 'latitude' => 6.4485, 'longitude' => 2.3557]);
        $source = ExternalSource::create([
            'name' => 'Source test',
            'url' => 'https://source.test/sitemap.xml',
            'robots_url' => 'https://source.test/robots.txt',
            'adapter' => 'sitemap',
            'is_active' => true,
            'collection_interval_minutes' => 60,
            'configuration' => [
                'allowed_hosts' => ['source.test'],
                'allowed_patterns' => ['#/annonce/chambres/location-#'],
                'excluded_patterns' => ['#\?#'],
                'search_allowed' => true,
                'crawl_delay_seconds' => 0,
            ],
        ]);
        Http::fake([
            'https://source.test/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'https://source.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://source.test/annonce/chambres/location-chambre-calavi-123</loc></url><url><loc>https://evil.test/annonce/chambres/location-interdite-999</loc></url></urlset>', 200),
            'https://source.test/annonce/chambres/location-chambre-calavi-123' => Http::response('<html><head><meta property="og:title" content="Chambre salon sanitaire à Abomey-Calavi"><meta name="description" content="Chambre salon propre avec sanitaires et photo"><meta property="og:image" content="https://source.test/photo.jpg"></head><body>Location à Abomey-Calavi Prix 35 000 FCFA / mois</body></html>', 200),
        ]);

        $this->assertSame(0, Artisan::call('findcasa:collect', ['source' => $source->id]));
        $this->assertDatabaseHas('collected_offers', [
            'source_url' => 'https://source.test/annonce/chambres/location-chambre-calavi-123',
            'monthly_rent' => 35000,
            'qualification_status' => 'priority',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('collected_offers', ['source_url' => 'https://evil.test/annonce/chambres/location-interdite-999']);
        Notification::assertSentTo($admin, FindCasaEventNotification::class);
    }

    public function test_openrouteservice_distance_is_normalized_for_frontend(): void
    {
        config(['services.openrouteservice.key' => 'test-key']);
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/driving-car' => Http::response([
                'routes' => [['summary' => ['distance' => 4250.5, 'duration' => 780.2]]],
            ], 200),
        ]);
        $institution = Institution::create(['name' => 'Université route', 'code' => 'route-u', 'category' => 'Universités publiques']);
        $campus = Campus::create(['institution_id' => $institution->id, 'name' => 'Campus route', 'latitude' => 6.4485, 'longitude' => 2.3557]);
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Logement routier',
            'description' => 'Description',
            'type' => 'Chambre salon simple',
            'district' => 'Godomey',
            'bedroom_count' => 1,
            'latitude' => 6.40,
            'longitude' => 2.30,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);

        $this->getJson("/api/properties/{$property->id}/route-distance?campus_id={$campus->id}")
            ->assertOk()
            ->assertJsonPath('data.distance_km', 4.25)
            ->assertJsonPath('data.duration_minutes', 14);
    }

    public function test_rss_and_json_adapters_discover_allowed_urls(): void
    {
        $institution = Institution::create(['name' => 'Université flux', 'code' => 'flux-u', 'category' => 'Universités publiques']);
        Campus::create(['institution_id' => $institution->id, 'name' => 'Campus Calavi', 'latitude' => 6.4485, 'longitude' => 2.3557]);
        $configuration = ['allowed_hosts' => ['feeds.test'], 'allowed_patterns' => ['#/annonce/#'], 'excluded_patterns' => [], 'search_allowed' => true, 'crawl_delay_seconds' => 0];
        $rss = ExternalSource::create(['name' => 'RSS test', 'url' => 'https://feeds.test/feed.xml', 'robots_url' => 'https://feeds.test/robots.txt', 'adapter' => 'rss', 'configuration' => $configuration]);
        $json = ExternalSource::create(['name' => 'JSON test', 'url' => 'https://feeds.test/offers.json', 'robots_url' => 'https://feeds.test/robots.txt', 'adapter' => 'json', 'configuration' => [...$configuration, 'items_path' => 'data', 'url_field' => 'link']]);
        Http::fake([
            'https://feeds.test/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'https://feeds.test/feed.xml' => Http::response('<?xml version="1.0"?><rss><channel><item><link>https://feeds.test/annonce/rss-1</link></item></channel></rss>', 200),
            'https://feeds.test/offers.json' => Http::response(['data' => [['link' => 'https://feeds.test/annonce/json-1']]], 200),
            'https://feeds.test/annonce/*' => Http::response('<html><head><meta property="og:title" content="Chambre salon à Abomey-Calavi"><meta property="og:image" content="https://feeds.test/photo.jpg"></head><body>Location Abomey-Calavi 30 000 FCFA / mois</body></html>', 200),
        ]);

        Artisan::call('findcasa:collect', ['source' => $rss->id]);
        Artisan::call('findcasa:collect', ['source' => $json->id]);
        $this->assertDatabaseHas('collected_offers', ['source_url' => 'https://feeds.test/annonce/rss-1']);
        $this->assertDatabaseHas('collected_offers', ['source_url' => 'https://feeds.test/annonce/json-1', 'qualification_status' => 'rejected']);
        $duplicate = CollectedOffer::where('source_url', 'https://feeds.test/annonce/json-1')->first();
        $this->assertNotNull($duplicate->duplicate_of_id);
    }

    public function test_collection_respects_frequency_and_disables_source_after_three_failures(): void
    {
        Notification::fake();
        User::factory()->create(['role' => 'admin']);
        $source = ExternalSource::create([
            'name' => 'Source fragile', 'url' => 'https://broken.test/sitemap.xml', 'robots_url' => 'https://broken.test/robots.txt',
            'adapter' => 'sitemap', 'is_active' => true, 'collection_interval_minutes' => 360,
            'last_collected_at' => now(), 'configuration' => ['allowed_hosts' => ['broken.test'], 'allowed_patterns' => ['#/#'], 'search_allowed' => true],
        ]);
        Http::fake(['https://broken.test/*' => Http::response('Erreur', 500)]);

        Artisan::call('findcasa:collect');
        $this->assertDatabaseCount('collection_runs', 0);
        Artisan::call('findcasa:collect', ['source' => $source->id]);
        Artisan::call('findcasa:collect', ['source' => $source->id]);
        Artisan::call('findcasa:collect', ['source' => $source->id]);

        $source->refresh();
        $this->assertFalse($source->is_active);
        $this->assertSame(3, $source->consecutive_failures);
        $this->assertNotNull($source->disabled_at);
    }

    public function test_admin_approval_publishes_external_offer_with_source_redirect(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = ExternalSource::create(['name' => 'Source approuvée', 'url' => 'https://source.test/sitemap-approved.xml', 'adapter' => 'sitemap']);
        $offer = CollectedOffer::create([
            'external_source_id' => $source->id,
            'source_url' => 'https://source.test/annonce/chambres/location-test-1',
            'title' => 'Chambre salon sanitaire test',
            'monthly_rent' => 40000,
            'city' => 'Abomey-Calavi',
            'property_type' => 'Chambre salon sanitaire',
            'description_excerpt' => 'Offre test collectée',
            'image_url' => 'https://source.test/images/room.jpg',
            'latitude' => 6.4485,
            'longitude' => 2.3557,
            'distance_km' => 1.2,
            'relevance_score' => 90,
            'qualification_status' => 'priority',
            'qualification_reasons' => [],
            'status' => 'pending',
            'discovered_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/collected-offers/{$offer->id}/review", ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('properties', ['external_url' => $offer->source_url, 'external_image_url' => 'https://source.test/images/room.jpg', 'origin' => 'collected', 'publication_status' => 'published']);
        $this->assertDatabaseHas('rooms', ['rent' => 40000, 'status' => 'available']);
        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonFragment(['external_url' => $offer->source_url, 'external_image_url' => 'https://source.test/images/room.jpg', 'origin' => 'collected']);
    }

    public function test_direct_owner_property_still_requires_moderation(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified', 'email_verified_at' => now()]);

        $response = $this->actingAs($owner)->postJson('/api/owner/properties', [
            'title' => 'Annonce directe à modérer',
            'description' => 'Description complète de l’annonce directe.',
            'type' => 'Chambre salon simple',
            'address' => 'Adresse directe',
            'district' => 'Calavi',
            'surface' => 22,
            'bedroom_count' => 1,
            'latitude' => 6.4485,
            'longitude' => 2.3557,
            'rooms' => [['name' => 'Chambre 1', 'rent' => 30000]],
        ])->assertCreated();

        $propertyId = $response->json('data.id');
        $this->assertDatabaseHas('properties', ['id' => $propertyId, 'origin' => 'direct', 'publication_status' => 'pending']);
        $this->getJson('/api/properties')->assertJsonMissing(['id' => $propertyId]);
    }

    public function test_admin_can_review_owner_details_and_download_private_identity_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('identity-documents/owner.pdf', 'document privé');
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create([
            'role' => 'owner',
            'phone' => '0157728807',
            'owner_verification_status' => 'pending',
            'identity_document_path' => 'identity-documents/owner.pdf',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/owners/pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $owner->id, 'email' => $owner->email, 'phone' => '0157728807', 'has_identity_document' => true])
            ->assertJsonMissing(['identity_document_path' => 'identity-documents/owner.pdf']);

        $this->actingAs($admin)
            ->get("/api/admin/owners/{$owner->id}/identity-document")
            ->assertOk();
    }

    public function test_admin_can_open_complete_property_before_moderation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Annonce à examiner',
            'description' => 'Description complète à contrôler',
            'type' => 'Chambre salon sanitaire',
            'address' => 'Adresse privée visible par admin',
            'district' => 'Zogbadjè',
            'surface' => 25,
            'bedroom_count' => 1,
            'latitude' => 6.44,
            'longitude' => 2.35,
            'publication_status' => 'pending',
            'origin' => 'direct',
        ]);
        Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 35000, 'deposit' => 20000, 'advance' => 35000]);

        $this->actingAs($admin)
            ->getJson("/api/admin/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Annonce à examiner')
            ->assertJsonPath('data.address', 'Adresse privée visible par admin')
            ->assertJsonPath('data.owner.email', $owner->email)
            ->assertJsonPath('data.rooms.0.rent', '35000.00');
    }

    public function test_accepting_reservation_creates_conversation_and_notifies_student(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Chambre acceptée',
            'description' => 'Description',
            'type' => 'Chambre salon sanitaire',
            'district' => 'Zogbadjè',
            'bedroom_count' => 1,
            'latitude' => 6.44,
            'longitude' => 2.35,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre A', 'rent' => 35000, 'deposit' => 15000, 'advance' => 35000, 'status' => 'available']);
        $reservation = \App\Models\Reservation::create([
            'student_id' => $student->id,
            'room_id' => $room->id,
            'start_date' => now()->addWeek(),
            'end_date' => now()->addMonths(6),
            'duration_months' => 6,
            'message' => 'Je souhaite visiter cette chambre.',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($owner)
            ->patchJson("/api/owner/reservations/{$reservation->id}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonStructure(['conversation_id']);

        $conversationId = $response->json('conversation_id');
        $this->assertNotNull($conversationId);
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'student_id' => $student->id, 'owner_id' => $owner->id, 'property_id' => $property->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationId, 'sender_id' => $student->id, 'body' => 'Je souhaite visiter cette chambre.']);
        Notification::assertSentTo($student, FindCasaEventNotification::class);

        $this->actingAs($student)
            ->postJson("/api/reservations/{$reservation->id}/confirm")
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversationId);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'reserved']);
    }

    public function test_owner_can_save_resume_and_submit_a_draft(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'owner_verification_status' => 'verified']);
        $draftResponse = $this->actingAs($owner)->postJson('/api/owner/properties/draft', [
            'title' => 'Brouillon logement',
            'type' => 'Chambre salon simple',
            'district' => 'Godomey',
            'bedroom_count' => 1,
        ])->assertCreated()->assertJsonPath('data.publication_status', 'draft');
        $propertyId = $draftResponse->json('data.id');

        $this->actingAs($owner)->postJson("/api/owner/properties/{$propertyId}/rooms", [
            'name' => 'Chambre 1', 'rent' => 35000, 'deposit' => 10000, 'advance' => 35000,
        ])->assertCreated();

        $this->actingAs($owner)->putJson("/api/owner/properties/{$propertyId}", [
            'title' => 'Logement finalisé', 'description' => 'Description complète', 'address' => 'Adresse complète',
            'district' => 'Godomey', 'surface' => 22, 'latitude' => 6.4, 'longitude' => 2.3,
            'submit' => true,
        ])->assertOk()->assertJsonPath('data.publication_status', 'pending');

        $this->actingAs($owner)->getJson("/api/owner/properties/{$propertyId}")
            ->assertOk()->assertJsonPath('data.rooms.0.rent', '35000.00');
    }

    public function test_owner_can_update_room_status_and_delete_inactive_room(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Gestion chambres', 'type' => 'Double chambre salon simple',
            'bedroom_count' => 2, 'publication_status' => 'draft', 'origin' => 'direct',
        ]);
        $first = Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 30000, 'status' => 'available']);
        $second = Room::create(['property_id' => $property->id, 'name' => 'Chambre 2', 'rent' => 32000, 'status' => 'available']);

        $this->actingAs($owner)->patchJson("/api/owner/rooms/{$first->id}", ['status' => 'unavailable'])
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
        $this->actingAs($owner)->deleteJson("/api/owner/rooms/{$second->id}")->assertOk();
        $this->assertDatabaseMissing('rooms', ['id' => $second->id]);
    }

    public function test_owner_can_list_and_delete_property_without_active_reservation(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'email_verified_at' => now()]);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Annonce supprimable', 'type' => 'Chambre salon simple',
            'bedroom_count' => 1, 'publication_status' => 'draft', 'origin' => 'direct',
        ]);
        Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 20000, 'status' => 'available']);

        $this->actingAs($owner)->getJson('/api/owner/properties')
            ->assertOk()
            ->assertJsonFragment(['id' => $property->id, 'title' => 'Annonce supprimable']);
        $this->actingAs($owner)->deleteJson("/api/owner/properties/{$property->id}")->assertOk();
        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->notify(new FindCasaEventNotification('Notification 1', 'Premier message'));
        $student->notify(new FindCasaEventNotification('Notification 2', 'Deuxième message'));
        $this->assertSame(2, $student->unreadNotifications()->count());

        $this->actingAs($student)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $student->fresh()->unreadNotifications()->count());
    }

    public function test_complete_reservation_lifecycle_releases_room_and_records_history(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Cycle complet', 'description' => 'Description',
            'type' => 'Chambre salon sanitaire', 'district' => 'Calavi', 'bedroom_count' => 1,
            'latitude' => 6.44, 'longitude' => 2.35, 'publication_status' => 'published', 'origin' => 'direct',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre 1', 'rent' => 30000, 'status' => 'reserved']);
        $reservation = \App\Models\Reservation::create([
            'student_id' => $student->id, 'room_id' => $room->id, 'start_date' => now(), 'end_date' => now()->addMonths(6),
            'duration_months' => 6, 'status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->actingAs($owner)->postJson("/api/owner/reservations/{$reservation->id}/rented")
            ->assertOk()->assertJsonPath('data.status', 'rented');
        $this->actingAs($owner)->postJson("/api/owner/reservations/{$reservation->id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'available']);
        $this->assertDatabaseHas('reservation_status_histories', ['reservation_id' => $reservation->id, 'from_status' => 'confirmed', 'to_status' => 'rented']);
        $this->assertDatabaseHas('reservation_status_histories', ['reservation_id' => $reservation->id, 'from_status' => 'rented', 'to_status' => 'completed']);
    }

    public function test_external_property_creates_contact_request_instead_of_reservation(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => null, 'title' => 'Offre externe', 'description' => 'Description',
            'type' => 'Chambre salon simple', 'district' => 'Calavi', 'bedroom_count' => 1,
            'latitude' => 6.44, 'longitude' => 2.35, 'publication_status' => 'published', 'origin' => 'collected',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre externe', 'rent' => 25000, 'status' => 'available']);

        $this->actingAs($student)->postJson('/api/reservations', [
            'room_id' => $room->id, 'start_date' => now()->addWeek()->toDateString(), 'duration_months' => 6, 'message' => 'Je suis intéressé.',
        ])->assertStatus(202)->assertJsonPath('requires_external_confirmation', true);

        $this->assertDatabaseHas('external_contact_requests', ['student_id' => $student->id, 'property_id' => $property->id, 'status' => 'pending']);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_pending_student_cannot_see_exact_address_but_accepted_student_can(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create([
            'owner_id' => $owner->id, 'title' => 'Adresse protégée', 'description' => 'Description',
            'type' => 'Chambre salon simple', 'address' => 'Maison 42 rue privée', 'district' => 'Calavi', 'bedroom_count' => 1,
            'latitude' => 6.44, 'longitude' => 2.35, 'publication_status' => 'published', 'origin' => 'direct',
        ]);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre', 'rent' => 30000, 'status' => 'available']);
        $reservation = \App\Models\Reservation::create([
            'student_id' => $student->id, 'room_id' => $room->id, 'start_date' => now(), 'end_date' => now()->addMonth(), 'duration_months' => 1, 'status' => 'pending',
        ]);

        $this->actingAs($student)->getJson("/api/properties/{$property->id}")->assertOk()->assertJsonMissing(['address' => 'Maison 42 rue privée']);
        $reservation->update(['status' => 'accepted']);
        $this->actingAs($student)->getJson("/api/properties/{$property->id}")->assertOk()->assertJsonPath('data.address', 'Maison 42 rue privée');
    }

    public function test_review_is_allowed_only_after_completed_location(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create(['owner_id' => $owner->id, 'title' => 'Logement avis', 'type' => 'Chambre salon simple', 'bedroom_count' => 1, 'publication_status' => 'published', 'origin' => 'direct']);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre', 'rent' => 25000, 'status' => 'rented']);
        $reservation = \App\Models\Reservation::create(['student_id' => $student->id, 'room_id' => $room->id, 'start_date' => now(), 'end_date' => now(), 'duration_months' => 1, 'status' => 'rented']);

        $this->actingAs($student)->postJson('/api/reviews', ['reservation_id' => $reservation->id, 'rating' => 5])->assertForbidden();
        $reservation->update(['status' => 'completed']);
        $this->actingAs($student)->postJson('/api/reviews', ['reservation_id' => $reservation->id, 'rating' => 5, 'comment' => 'Très bon logement'])->assertCreated();
    }

    public function test_favorite_users_are_notified_when_room_availability_changes(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create(['owner_id' => $owner->id, 'title' => 'Logement favori', 'type' => 'Chambre salon simple', 'bedroom_count' => 1, 'publication_status' => 'published', 'origin' => 'direct']);
        $room = Room::create(['property_id' => $property->id, 'name' => 'Chambre', 'rent' => 25000, 'status' => 'available']);
        \App\Models\Favorite::create(['user_id' => $student->id, 'property_id' => $property->id]);

        $this->actingAs($owner)->patchJson("/api/owner/rooms/{$room->id}", ['status' => 'unavailable'])->assertOk();
        Notification::assertSentTo($student, FindCasaEventNotification::class);
    }

    public function test_admin_can_suspend_user_and_action_is_audited(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'is_suspended' => false]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$student->id}/suspension", ['suspended' => true, 'reason' => 'Compte frauduleux'])
            ->assertOk()
            ->assertJsonPath('data.is_suspended', true);

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.suspended', 'target_type' => 'User', 'target_id' => $student->id]);
        Notification::assertSentTo($student, FindCasaEventNotification::class);
    }

    public function test_admin_statistics_and_institution_crud_are_available(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'student']);

        $this->actingAs($admin)->getJson('/api/admin/statistics')
            ->assertOk()
            ->assertJsonPath('data.students', 1)
            ->assertJsonStructure(['data' => ['users', 'owners', 'properties', 'average_rent', 'collected_share']]);

        $institutionResponse = $this->actingAs($admin)->postJson('/api/admin/institutions', [
            'name' => 'Institut administré', 'code' => 'IA-TEST', 'category' => 'Établissements privés indépendants',
        ])->assertCreated();
        $institutionId = $institutionResponse->json('data.id');
        $this->actingAs($admin)->postJson("/api/admin/institutions/{$institutionId}/campuses", [
            'name' => 'Campus principal', 'address' => 'Calavi', 'latitude' => 6.44, 'longitude' => 2.35,
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/admin/institutions')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Institut administré'])
            ->assertJsonFragment(['name' => 'Campus principal']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.created', 'target_id' => $institutionId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campus.created']);
    }

    public function test_admin_can_suspend_property_when_resolving_report(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $reporter = User::factory()->create(['role' => 'student']);
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create(['owner_id' => $owner->id, 'title' => 'Annonce signalée', 'type' => 'Chambre salon simple', 'bedroom_count' => 1, 'publication_status' => 'published', 'origin' => 'direct']);
        $report = \App\Models\Report::create(['reporter_id' => $reporter->id, 'property_id' => $property->id, 'reason' => 'contenu_frauduleux', 'status' => 'pending']);

        $this->actingAs($admin)->patchJson("/api/admin/reports/{$report->id}/review", ['status' => 'resolved', 'suspend_property' => true, 'admin_note' => 'Annonce trompeuse'])->assertOk();
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'publication_status' => 'rejected']);
        Notification::assertSentTo($reporter, FindCasaEventNotification::class);
    }

    public function test_new_report_notifies_active_administrators(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_suspended' => false]);
        $reporter = User::factory()->create(['role' => 'student', 'email_verified_at' => now()]);
        $owner = User::factory()->create(['role' => 'owner']);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Annonce à signaler',
            'type' => 'Chambre salon simple',
            'bedroom_count' => 1,
            'publication_status' => 'published',
            'origin' => 'direct',
        ]);

        $this->actingAs($reporter)
            ->postJson("/api/properties/{$property->id}/reports", ['reason' => 'prix_incorrect'])
            ->assertCreated();

        Notification::assertSentTo($admin, FindCasaEventNotification::class, fn ($notification) => $notification->toArray($admin)['report_id'] !== null);
    }

    public function test_conversation_list_contains_unread_message_count(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $student = User::factory()->create(['role' => 'student']);
        $property = Property::create(['owner_id' => $owner->id, 'title' => 'Conversation test', 'type' => 'Chambre salon simple', 'bedroom_count' => 1, 'publication_status' => 'published', 'origin' => 'direct']);
        $conversation = \App\Models\Conversation::create(['property_id' => $property->id, 'student_id' => $student->id, 'owner_id' => $owner->id]);
        \App\Models\Message::create(['conversation_id' => $conversation->id, 'sender_id' => $student->id, 'body' => 'Bonjour propriétaire']);

        $this->actingAs($owner)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_messages_count', 1);
        $this->actingAs($owner)->getJson("/api/conversations/{$conversation->id}/messages")->assertOk();
        $this->actingAs($owner)->getJson('/api/conversations')->assertJsonPath('data.0.unread_messages_count', 0);
    }

    private function fakePng(string $name, int $width, int $height): UploadedFile
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };
        $row = "\0".str_repeat("\xff\xff\xff", $width);
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $height), 9))
            .$chunk('IEND', '');
        $path = tempnam(sys_get_temp_dir(), 'findcasa-png-');
        file_put_contents($path, $png);
        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
