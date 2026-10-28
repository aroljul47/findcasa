<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'Universités publiques' => [
                ['uac', 'Université d’Abomey-Calavi (UAC)', ['Abomey-Calavi']],
                ['up', 'Université de Parakou (UP)', ['Parakou']],
                ['unstim', 'Université Nationale des Sciences, Technologies, Ingénierie et Mathématiques (UNSTIM)', ['Abomey']],
                ['una', 'Université Nationale d’Agriculture (UNA)', ['Kétou']],
            ],
            'Universités et grands établissements privés' => [
                ['uatm-gasa', 'Université Africaine de Technologie et de Management (UATM/GASA)', ['Cotonou']],
                ['ucao-benin', 'Université Catholique de l’Afrique de l’Ouest (UCAO) – Bénin', ['Cotonou']],
                ['upao', 'Université Protestante de l’Afrique de l’Ouest (UPAO)', ['Porto-Novo']],
                ['upi-onm', 'Université Polytechnique Internationale d’Obiang Nguema Mbasogo (UPI-ONM)', ['Cotonou']],
                ['irgib', 'IRGIB-Africa', ['Cotonou']],
                ['hnaub', 'Houdégbé North American University Benin (HNAUB)', ['Abomey-Calavi']],
                ['iup', 'Institut Universitaire Panafricain (IUP)', ['Cotonou']],
            ],
            'Établissements privés indépendants' => [
                ['hecm', 'Haute École de Commerce et de Management (HECM)', ['Calavi', 'Akpakpa', 'Jéricho', 'Bohicon', 'Parakou', 'Porto-Novo']],
                ['esgis', 'École Supérieure de Gestion d’Informatique et des Sciences (ESGIS)', ['Cotonou']],
                ['iim', 'Institut International de Management (IIM)', ['Cotonou']],
                ['isma', 'Institut Supérieur des Métiers de l’Audiovisuel (ISMA)', ['Cotonou']],
                ['ism-adonai', 'Institut Supérieur de Management Adonaï (ISM Adonaï)', ['Cotonou']],
                ['cours-sonou', 'Les Cours Sonou', ['Cotonou']],
                ['esae', 'École Supérieure d’Administration et d’Économie (ESAE)', ['Cotonou']],
                ['esep-berger', 'École Supérieure d’Enseignement Professionnel – Le Berger (ESEP)', ['Cotonou']],
                ['esgc', 'École Supérieure de Génie Civil (ESGC)', ['Cotonou']],
                ['iscg', 'Institut Supérieur de Communication et de Gestion (ISCG)', ['Cotonou']],
                ['pigier', 'Pigier Bénin', ['Cotonou']],
                ['cerco', 'Institut CERCO', ['Cotonou']],
                ['esm', 'École Supérieure de Management (ESM)', ['Cotonou']],
                ['iseg', 'Institut Supérieur d’Expertise et de Gestion (ISEG)', ['Cotonou']],
                ['esmer', 'École Supérieure des Métiers des Énergies Renouvelables (ESMER)', ['Cotonou']],
                ['essf', 'École Supérieure Sainte Félicité (ESSF)', ['Cotonou']],
                ['jean-michel-faucon', 'École Supérieure Jean-Michel Le Faucon', ['Cotonou']],
                ['jean-paul-ii', 'Institut Jean-Paul II', ['Cotonou']],
                ['saint-salomon', 'Institut Supérieur de Management et de Technologies Saint-Salomon', ['Cotonou']],
                ['polytech-ada', 'Polytech Ada', ['Cotonou']],
                ['esec', 'École Supérieure d’Expertise Comptable (ESEC)', ['Cotonou']],
                ['iust-as', 'Institut Universitaire des Sciences et Techniques Ajavon Sébastien (IUST-AS)', ['Cotonou']],
                ['sapientia', 'École de Formation des Enseignants du Secondaire Sapientia', ['Cotonou']],
                ['esgtic', 'École Supérieure de Génie Télécoms et TIC – Ecce Homo (ESGTIC)', ['Cotonou']],
                ['laura-vicuna', 'Institut Supérieur de Formation des Éducateurs Spécialisés Laura Vicuna', ['Cotonou']],
                ['esperanza', 'École Supérieure Esperanza', ['Cotonou']],
                ['esigt', 'École Supérieure des Ingénieurs Géomètres Topographes (ESIGT)', ['Cotonou']],
                ['polytech-citoyen', 'Institut Supérieur Polytech Le Citoyen', ['Cotonou']],
                ['isfg', 'Institut Supérieur de Formation et de Gestion (ISFG)', ['Cotonou']],
                ['isst', 'Institut Supérieur des Sciences et Techniques', ['Cotonou']],
                ['gasa-formation', 'GASA Formation', ['Cotonou']],
            ],
        ];

        foreach ($groups as $category => $entries) {
            foreach ($entries as [$code, $name, $campusNames]) {
                $institution = Institution::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'category' => $category, 'is_active' => true],
                );

                foreach ($campusNames as $campusName) {
                    $coordinates = [
                        'Abomey-Calavi' => [6.4485, 2.3557],
                        'Calavi' => [6.4485, 2.3557],
                        'Cotonou' => [6.3703, 2.3912],
                        'Porto-Novo' => [6.4969, 2.6289],
                        'Parakou' => [9.3372, 2.6303],
                        'Abomey' => [7.1829, 1.9912],
                        'Kétou' => [7.3633, 2.5998],
                        'Akpakpa' => [6.3703, 2.4312],
                        'Jéricho' => [6.3658, 2.4018],
                        'Bohicon' => [7.1783, 2.0667],
                    ][$campusName] ?? [6.3703, 2.3912];

                    $institution->campuses()->updateOrCreate(
                        ['name' => $campusName],
                        ['address' => $campusName.', Bénin', 'latitude' => $coordinates[0], 'longitude' => $coordinates[1], 'is_active' => true],
                    );
                }
            }
        }
    }
}
