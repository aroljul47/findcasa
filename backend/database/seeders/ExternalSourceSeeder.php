<?php

namespace Database\Seeders;

use App\Models\ExternalSource;
use Illuminate\Database\Seeder;

class ExternalSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sources = [
            ['name' => 'OWE Bénin', 'url' => 'https://owe.bj/sitemap.xml', 'robots_url' => 'https://owe.bj/robots.txt', 'adapter' => 'sitemap', 'collection_interval_minutes' => 720, 'configuration' => ['allowed_hosts' => ['owe.bj', 'www.owe.bj'], 'allowed_patterns' => ['#/ad/details/#'], 'excluded_patterns' => ['#/legal/#', '#/admin/#', '#/user/#'], 'seed_urls' => ['https://owe.bj/ad/details/maison-spacieuse-avec-cour-arriere-facebook_ad_plugin-63f4e5f7-3391-4cd7-bfb1-2cff8ea4f524/', 'https://owe.bj/ad/details/chambre-salon-spacieux-sanitaires-modernes-facebook_ad_plugin-218c614f-2b19-4230-88ee-5b90a3aa7058/'], 'search_allowed' => true, 'crawl_delay_seconds' => 1]],
            ['name' => 'Keur-Immo', 'url' => 'https://keur-immo.com/sitemap.xml', 'robots_url' => 'https://keur-immo.com/robots.txt', 'adapter' => 'sitemap', 'collection_interval_minutes' => 720, 'configuration' => ['allowed_hosts' => ['keur-immo.com', 'www.keur-immo.com'], 'allowed_patterns' => ['#/(annonce|property|bien|location)/#'], 'excluded_patterns' => [], 'search_allowed' => true, 'crawl_delay_seconds' => 10]],
            ['name' => 'CoinAfrique Bénin', 'url' => 'https://bj.coinafrique.com/sitemaps.xml', 'robots_url' => 'https://bj.coinafrique.com/robots.txt', 'adapter' => 'sitemap', 'collection_interval_minutes' => 720, 'configuration' => ['allowed_hosts' => ['bj.coinafrique.com'], 'allowed_patterns' => ['#/annonce/(appartements|appartements-meubles|chambres|villas)/location-#'], 'excluded_patterns' => ['#\?#', '#/search#', '#/profil/#', '#/dashboard/#', '#/collections/#'], 'search_allowed' => true, 'crawl_delay_seconds' => 1]],
        ];

        foreach ($sources as $source) ExternalSource::updateOrCreate(['url' => $source['url']], $source);
    }
}
