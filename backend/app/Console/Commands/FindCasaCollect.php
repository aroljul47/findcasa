<?php

namespace App\Console\Commands;

use App\Models\CollectedOffer;
use App\Models\Campus;
use App\Models\ExternalSource;
use App\Models\User;
use App\Notifications\FindCasaEventNotification;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class FindCasaCollect extends Command
{
    protected $signature = 'findcasa:collect {source? : Identifiant de la source à traiter}';
    protected $description = 'Découvre les offres autorisées et les place en attente de validation';

    public function handle(): int
    {
        $sources = ExternalSource::query()
            ->where('is_active', true)
            ->when($this->argument('source'), fn ($query) => $query->whereKey($this->argument('source')))
            ->get();

        foreach ($sources as $source) $this->collectSource($source, (bool) $this->argument('source'));

        return self::SUCCESS;
    }

    private function collectSource(ExternalSource $source, bool $force = false): void
    {
        if (!$force && $source->last_collected_at?->addMinutes($source->collection_interval_minutes)->isFuture()) {
            $this->line($source->name.': prochaine collecte non échue.');
            return;
        }
        $run = $source->runs()->create(['status' => 'started', 'started_at' => now()]);

        try {
            if (!in_array($source->adapter, ['sitemap', 'rss', 'json'], true)) throw new RuntimeException('Cet adaptateur n’est pas pris en charge.');
            if (!($source->configuration['search_allowed'] ?? false)) throw new RuntimeException('La source n’autorise pas l’indexation de recherche.');
            $this->assertRobotsAllowsSearch($source);

            $urls = [...$this->discoverUrls($source), ...($source->configuration['seed_urls'] ?? [])];
            $candidates = collect($urls)->filter(fn (string $url) => $this->isAllowedUrl($source, $url))->unique()->take(25)->values();
            $created = 0;

            foreach ($candidates as $url) {
                $html = $this->fetchHtml($source, $url);
                if ($html === null) continue;
                $path = parse_url($url, PHP_URL_PATH) ?: '';
                $slug = basename(rtrim($path, '/'));
                $category = collect(explode('/', trim($path, '/')))->get(1);
                $extracted = $this->extractOffer($url, $html, $category);
                $qualification = $this->qualify($extracted);
                $fingerprint = $this->fingerprint($extracted);
                $duplicate = $fingerprint ? CollectedOffer::where('fingerprint', $fingerprint)->where('source_url', '<>', $url)->first() : null;
                if ($duplicate) {
                    $qualification['status'] = 'rejected';
                    $qualification['reasons'][] = 'Doublon probable détecté par prix, localisation et image.';
                }
                $offer = CollectedOffer::firstOrNew(['source_url' => $url]);
                $offer->fill([
                    'external_source_id' => $source->id,
                    'title' => $extracted['title'] ?: $this->titleFromSlug($slug),
                    'category' => $category,
                    'monthly_rent' => $extracted['monthly_rent'],
                    'city' => $extracted['city'],
                    'property_type' => $extracted['property_type'],
                    'description_excerpt' => $extracted['description_excerpt'],
                    'image_url' => $extracted['image_url'],
                    'latitude' => $qualification['latitude'],
                    'longitude' => $qualification['longitude'],
                    'nearest_campus_id' => $qualification['nearest_campus_id'],
                    'distance_km' => $qualification['distance_km'],
                    'relevance_score' => $qualification['score'],
                    'qualification_status' => $qualification['status'],
                    'qualification_reasons' => $qualification['reasons'],
                    'fingerprint' => $fingerprint,
                    'duplicate_of_id' => $duplicate?->id,
                    'metadata' => ['source' => $source->name, 'usage' => 'search-reference', 'is_rental' => $extracted['is_rental']],
                    'last_seen_at' => now(),
                ]);
                if (!$offer->exists) {
                    $offer->status = 'pending';
                    $offer->discovered_at = now();
                }
                $offer->save();
                if ($offer->wasRecentlyCreated) $created++;
            }

            $run->update(['status' => 'completed', 'found_count' => $candidates->count(), 'created_count' => $created, 'finished_at' => now()]);
            $source->update(['last_collected_at' => now(), 'last_error' => null, 'consecutive_failures' => 0, 'disabled_at' => null]);
            if ($created > 0) {
                User::where('role', 'admin')->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification('Nouvelles offres collectées', "{$created} nouvelle(s) offre(s) de {$source->name} attendent une vérification.", ['source_id' => $source->id])));
            }
            $this->info("{$source->name}: {$candidates->count()} lien(s), {$created} nouvelle(s) offre(s) en attente.");
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
            $failures = $source->consecutive_failures + 1;
            $disable = $failures >= 3;
            $source->update(['last_collected_at' => now(), 'last_error' => $exception->getMessage(), 'consecutive_failures' => $failures, 'is_active' => $disable ? false : $source->is_active, 'disabled_at' => $disable ? now() : null]);
            User::where('role', 'admin')->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification('Échec de collecte', "La collecte {$source->name} a échoué.", ['source_id' => $source->id])));
            $this->warn($source->name.': '.$exception->getMessage());
        }
    }

    private function client(): PendingRequest
    {
        return Http::timeout(30)->withUserAgent('FindCasaIndexer/1.0')->accept('application/xml,text/xml,text/html');
    }

    private function discoverUrls(ExternalSource $source): array
    {
        return match ($source->adapter) {
            'sitemap' => $this->discoverSitemapUrls($source->url),
            'rss' => $this->discoverRssUrls($source->url),
            'json' => $this->discoverJsonUrls($source),
            default => [],
        };
    }

    private function discoverRssUrls(string $url): array
    {
        $response = $this->client()->get($url);
        if (!$response->successful()) throw new RuntimeException('Flux RSS inaccessible: HTTP '.$response->status());
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        if (!$xml instanceof SimpleXMLElement) throw new RuntimeException('Flux RSS invalide.');
        $links = array_map('strval', $xml->xpath('//item/link') ?: []);
        if (!$links) $links = array_map('strval', $xml->xpath('//*[local-name()="entry"]/*[local-name()="link"]/@href') ?: []);
        return $links;
    }

    private function discoverJsonUrls(ExternalSource $source): array
    {
        $response = $this->client()->acceptJson()->get($source->url);
        if (!$response->successful()) throw new RuntimeException('Source JSON inaccessible: HTTP '.$response->status());
        $payload = $response->json();
        $itemsPath = $source->configuration['items_path'] ?? null;
        $urlField = $source->configuration['url_field'] ?? 'url';
        $items = $itemsPath ? data_get($payload, $itemsPath, []) : $payload;
        if (!is_array($items)) throw new RuntimeException('Configuration JSON invalide.');
        return collect($items)->map(fn ($item) => is_array($item) ? data_get($item, $urlField) : null)->filter()->values()->all();
    }

    private function assertRobotsAllowsSearch(ExternalSource $source): void
    {
        if (!$source->robots_url) return;
        $response = $this->client()->get($source->robots_url);
        if (!$response->successful()) throw new RuntimeException('robots.txt inaccessible.');
        if (preg_match('/Content-Signal:.*search\s*=\s*no/i', $response->body())) throw new RuntimeException('robots.txt interdit l’indexation de recherche.');
    }

    private function discoverSitemapUrls(string $sitemapUrl, int $depth = 0): array
    {
        if ($depth > 2) return [];
        $response = $this->client()->get($sitemapUrl);
        if (!$response->successful()) throw new RuntimeException('Sitemap inaccessible: HTTP '.$response->status());
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        if (!$xml instanceof SimpleXMLElement) throw new RuntimeException('Sitemap XML invalide.');
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]') ?: []);
        if (str_ends_with(strtolower($xml->getName()), 'sitemapindex') || str_contains($response->body(), '<sitemapindex')) {
            return collect($locations)->take(10)->flatMap(fn ($url) => $this->discoverSitemapUrls($url, $depth + 1))->all();
        }
        return $locations;
    }

    private function isAllowedUrl(ExternalSource $source, string $url): bool
    {
        $configuration = $source->configuration ?? [];
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if (!in_array($host, $configuration['allowed_hosts'] ?? [], true)) return false;
        foreach ($configuration['excluded_patterns'] ?? [] as $pattern) if (preg_match($pattern, $url)) return false;
        foreach ($configuration['allowed_patterns'] ?? [] as $pattern) if (preg_match($pattern, $url)) return true;
        return false;
    }

    private function fetchHtml(ExternalSource $source, string $url): ?string
    {
        $delay = min((int) ($source->configuration['crawl_delay_seconds'] ?? 1), 10);
        if ($delay > 0) sleep($delay);
        try {
            $response = $this->client()->get($url);
            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractOffer(string $url, string $html, ?string $category): array
    {
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $meta = fn (string $property) => trim((string) ($xpath->query("//meta[@property='{$property}' or @name='{$property}']/@content")->item(0)?->nodeValue ?? ''));
        $body = preg_replace('/\s+/u', ' ', trim((string) $document->textContent));
        $title = preg_replace('/\s+-\s+(OWE|CoinAfrique).*$/iu', '', $meta('og:title'));
        if (!$title) $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $description = $meta('description') ?: $meta('og:description');
        $image = $meta('og:image');
        if ($image && !str_starts_with($image, 'http')) $image = rtrim(parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST), '/').'/'.ltrim($image, '/');

        $rent = null;
        if (preg_match('/([0-9][0-9\s\x{00A0}\x{202F}.]*)\s*FCFA\s*(?:\/\s*)?(?:par\s+)?mois/iu', $body, $match)) {
            $rent = (int) preg_replace('/\D/u', '', $match[1]);
        }

        $city = null;
        foreach (['Abomey-Calavi', 'Cotonou', 'Porto-Novo', 'Parakou', 'Bohicon', 'Abomey', 'Kétou', 'Godomey', 'Akpakpa', 'Agla', 'Zogbadjè', 'Arconville'] as $candidate) {
            if (stripos($body, $candidate) !== false || stripos($url, strtolower(str_replace([' ', 'è'], ['-', 'e'], $candidate))) !== false) { $city = $candidate; break; }
        }

        $haystack = mb_strtolower($title.' '.$description.' '.$category);
        $propertyType = match (true) {
            str_contains($haystack, 'deux chambre'), str_contains($haystack, '2 chambre') => 'Double chambre salon sanitaire',
            str_contains($haystack, 'chambre salon'), str_contains($haystack, 'chambre et salon') => str_contains($haystack, 'sanitaire') ? 'Chambre salon sanitaire' : 'Chambre salon simple',
            str_contains($haystack, 'studio'), str_contains($haystack, 'chambre') => 'Entrée coucher simple',
            default => null,
        };

        return [
            'title' => mb_substr(trim($title), 0, 255),
            'monthly_rent' => $rent,
            'city' => $city,
            'property_type' => $propertyType,
            'description_excerpt' => $description ? mb_substr(trim($description), 0, 600) : null,
            'image_url' => $image ?: null,
            'is_rental' => str_contains(mb_strtolower($body.' '.$url), 'location') || str_contains(mb_strtolower($body), 'fcfa / mois'),
        ];
    }

    private function qualify(array $offer): array
    {
        $reasons = [];
        $score = 0;
        $coordinates = $this->cityCoordinates($offer['city']);
        $nearest = $coordinates ? $this->nearestCampus($coordinates[0], $coordinates[1]) : null;

        if (!$offer['is_rental']) $reasons[] = 'Offre non identifiée comme location.';
        if ($offer['monthly_rent'] === null) $reasons[] = 'Prix mensuel absent.';
        elseif ($offer['monthly_rent'] > config('findcasa.max_monthly_rent')) $reasons[] = 'Loyer supérieur à 45 000 FCFA.';
        else $score += 25;
        if (!$offer['property_type']) $reasons[] = 'Type de logement étudiant non identifié.';
        else $score += 15;
        if (!$coordinates) $reasons[] = 'Localisation insuffisante pour calculer la distance.';
        elseif (!$nearest) $reasons[] = 'Aucun campus géolocalisé disponible.';
        elseif ($nearest['distance_km'] > 15) $reasons[] = 'Logement situé à plus de 15 km du campus le plus proche.';
        elseif ($nearest['distance_km'] <= 2) $score += 30;
        elseif ($nearest['distance_km'] <= 5) $score += 20;
        else $score += 10;
        if ($offer['image_url']) $score += 10;
        if ($offer['description_excerpt']) $score += 10;
        if ($offer['city']) $score += 10;

        $hardReject = !$offer['is_rental'] || ($offer['monthly_rent'] !== null && $offer['monthly_rent'] > config('findcasa.max_monthly_rent')) || ($nearest && $nearest['distance_km'] > 15);
        $incomplete = $offer['monthly_rent'] === null || !$offer['property_type'] || !$coordinates;
        $status = $hardReject ? 'rejected' : ($incomplete ? 'incomplete' : ($score >= 70 ? 'priority' : ($score >= 50 ? 'review' : 'rejected')));

        return [
            'latitude' => $coordinates[0] ?? null,
            'longitude' => $coordinates[1] ?? null,
            'nearest_campus_id' => $nearest['campus_id'] ?? null,
            'distance_km' => $nearest['distance_km'] ?? null,
            'score' => min($score, 100),
            'status' => $status,
            'reasons' => $reasons,
        ];
    }

    private function cityCoordinates(?string $city): ?array
    {
        if (!$city) return null;
        return [
            'Abomey-Calavi' => [6.4485, 2.3557], 'Godomey' => [6.3650, 2.3350], 'Zogbadjè' => [6.4250, 2.3500], 'Arconville' => [6.4300, 2.3300],
            'Cotonou' => [6.3703, 2.3912], 'Agla' => [6.3840, 2.3610], 'Akpakpa' => [6.3703, 2.4312], 'Porto-Novo' => [6.4969, 2.6289],
            'Parakou' => [9.3372, 2.6303], 'Bohicon' => [7.1783, 2.0667], 'Abomey' => [7.1829, 1.9912], 'Kétou' => [7.3633, 2.5998],
        ][$city] ?? null;
    }

    private function nearestCampus(float $latitude, float $longitude): ?array
    {
        return Campus::query()->whereNotNull('latitude')->whereNotNull('longitude')->get()->map(function (Campus $campus) use ($latitude, $longitude) {
            $latDelta = deg2rad((float) $campus->latitude - $latitude);
            $lonDelta = deg2rad((float) $campus->longitude - $longitude);
            $a = sin($latDelta / 2) ** 2 + cos(deg2rad($latitude)) * cos(deg2rad((float) $campus->latitude)) * sin($lonDelta / 2) ** 2;
            return ['campus_id' => $campus->id, 'distance_km' => round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2)];
        })->sortBy('distance_km')->first();
    }

    private function fingerprint(array $offer): ?string
    {
        if (!$offer['monthly_rent'] || !$offer['city']) return null;
        $image = $offer['image_url'] ? strtolower(basename(parse_url($offer['image_url'], PHP_URL_PATH) ?: '')) : '';
        $identity = implode('|', [$offer['monthly_rent'], mb_strtolower($offer['city']), mb_strtolower((string) $offer['property_type']), $image]);
        return hash('sha256', $identity);
    }

    private function titleFromSlug(string $slug): string
    {
        $title = preg_replace('/-\d+$/', '', $slug);
        return ucfirst(trim(str_replace(['-', '_'], ' ', $title)));
    }
}
