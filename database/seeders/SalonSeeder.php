<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SalonSeeder extends Seeder
{
    public function run(): void
    {
        $clients = User::factory()->count(15)->create();

        $salonsData = [
            [
                'name'        => 'Salon Élégance',
                'description' => 'Un salon de coiffure chic spécialisé dans les coupes tendances et les soins capillaires haut de gamme.',
                'address'     => '23 Rue Mohamed V, Plateau',
                'city'        => 'Dakar',
                'phone'       => '771234567',
                'image'       => 'cherosi-AV0KNliGvQc-unsplash.jpg',
            ],
            [
                'name'        => "L'Atelier Coiffure",
                'description' => 'Une équipe passionnée qui allie techniques modernes et conseils personnalisés pour révéler votre style.',
                'address'     => 'Route de la Corniche, Almadies',
                'city'        => 'Dakar',
                'phone'       => '781234568',
                'image'       => 'giorgio-trovato-gb6gtiTZKB8-unsplash.jpg',
            ],
            [
                'name'        => 'Barber Shop Prestige',
                'description' => "Un barbershop authentique pour une coupe et un rasage soignés dans une ambiance vintage.",
                'address'     => '67 Avenue Blaise Diagne, Médina',
                'city'        => 'Dakar',
                'phone'       => '763456789',
                'image'       => 'greg-trowman-jsuWg7IXx1k-unsplash.jpg',
            ],
            [
                'name'        => 'Beauté & Sens',
                'description' => 'Institut de beauté dédié au bien-être : soins du visage, manucure et massages relaxants.',
                'address'     => 'Villa 8, Sacré-Cœur 2',
                'city'        => 'Dakar',
                'phone'       => '770987654',
                'image'       => 'lindsay-cash-Md_DhaFsnCQ-unsplash.jpg',
            ],
            [
                'name'        => 'Glamour Studio',
                'description' => 'Studio de beauté moderne offrant coiffure, maquillage et soins esthétiques pour toutes les occasions.',
                'address'     => '14 Rue 10 bis, Mermoz',
                'city'        => 'Dakar',
                'phone'       => '783456789',
                'image'       => 'mockup-free-CdQE-EA_R04-unsplash.jpg',
            ],
            [
                'name'        => 'Spa Sérénité',
                'description' => 'Un havre de paix proposant massages, soins du corps et rituels de relaxation sur mesure.',
                'address'     => '8 Avenue Léopold Sédar Senghor',
                'city'        => 'Thiès',
                'phone'       => '773456789',
                'image'       => 'raajit-sharma-7Q1Jwr5BhgI-unsplash.jpg',
            ],
            [
                'name'        => 'Coiffure Tendance',
                'description' => 'Salon familial reconnu pour ses colorations créatives et son accueil chaleureux.',
                'address'     => '15 Rue de France, Ndiolofane',
                'city'        => 'Saint-Louis',
                'phone'       => '769876543',
                'image'       => 'raajit-sharma-qLy3Ag2h7Bg-unsplash.jpg',
            ],
            [
                'name'        => 'Le Salon Chic',
                'description' => 'Coiffure et esthétique réunies dans un cadre élégant, pour un moment de détente assuré.',
                'address'     => '4 Avenue Cheikh Anta Diop, Point E',
                'city'        => 'Dakar',
                'phone'       => '772345678',
                'image'       => 'rosa-rafael-Pe9IXUuC6QU-unsplash.jpg',
            ],
            [
                'name'        => 'Beauty Lounge',
                'description' => "Un espace beauté contemporain mêlant coiffure, manucure et soins du visage personnalisés.",
                'address'     => '32 Avenue Valdiodio Ndiaye',
                'city'        => 'Mbour',
                'phone'       => '786543210',
                'image'       => 'tile-merchant-ireland-g2u8gq5XcwE-unsplash.jpg',
            ],
        ];

        $serviceCatalog = [
            [
                'name' => 'Coupe Femme', 'category' => 'Coiffure', 'price' => 6000, 'duration' => 45, 'image' => '/images/services/coiffure.jpg',
                'descriptions' => [
                    'Coupe et coiffage personnalisés pour révéler votre style, réalisés par nos experts coiffeurs.',
                    'Une coupe sur-mesure adaptée à votre morphologie et à vos envies, avec finitions soignées.',
                    'Coupe tendance avec brushing inclus pour un résultat impeccable qui dure.',
                ],
            ],
            [
                'name' => 'Coupe Homme', 'category' => 'Coiffure', 'price' => 3500, 'duration' => 30, 'image' => '/images/services/coiffure.jpg',
                'descriptions' => [
                    'Coupe classique ou moderne pour homme, avec conseils personnalisés et finitions nettes.',
                    'Taille et coiffage précis pour un look soigné, adapté à votre style de vie.',
                    'Coupe homme incluant shampoing et coiffage pour un résultat impeccable.',
                ],
            ],
            [
                'name' => 'Coloration', 'category' => 'Coloration', 'price' => 20000, 'duration' => 90, 'image' => '/images/services/coloration.jpg',
                'descriptions' => [
                    'Coloration professionnelle pour un résultat lumineux, avec protection maximale de votre chevelure.',
                    'Changez de couleur en toute sécurité grâce à nos produits respectueux de la fibre capillaire.',
                    'Colorations tendances ou naturelles, adaptées à votre carnation et à vos désirs.',
                ],
            ],
            [
                'name' => 'Balayage', 'category' => 'Coloration', 'price' => 30000, 'duration' => 120, 'image' => '/images/services/coloration.jpg',
                'descriptions' => [
                    'Balayage naturel ou intense pour illuminer votre chevelure et apporter profondeur et éclat.',
                    'Technique de balayage réalisée à la main pour un effet soleil personnalisé et durable.',
                    'Balayage multi-tons pour un résultat sophistiqué qui booste votre coiffure au quotidien.',
                ],
            ],
            [
                'name' => 'Manucure', 'category' => 'Manucure', 'price' => 6000, 'duration' => 40, 'image' => '/images/services/manucure.jpg',
                'descriptions' => [
                    'Soin complet des mains incluant limage, cuticules et pose de vernis pour des mains impeccables.',
                    'Manucure soignée avec gommage et hydratation pour des mains douces et des ongles parfaits.',
                    'Mise en beauté des mains avec choix parmi une large gamme de vernis tendance.',
                ],
            ],
            [
                'name' => 'Pose Vernis Semi-Permanent', 'category' => 'Manucure', 'price' => 8000, 'duration' => 45, 'image' => '/images/services/manucure.jpg',
                'descriptions' => [
                    'Pose de vernis semi-permanent longue durée pour des ongles parfaits jusqu\'à trois semaines.',
                    'Vernis semi-permanent résistant aux chocs et à l\'eau, pour un rendu impeccable durable.',
                    'Application de gel semi-permanent pour une brillance et une tenue incomparables.',
                ],
            ],
            [
                'name' => 'Soin du Visage', 'category' => 'Soin', 'price' => 15000, 'duration' => 60, 'image' => '/images/services/soin.jpg',
                'descriptions' => [
                    'Soin visage profond adapté à votre type de peau, pour un teint éclatant et une peau régénérée.',
                    'Nettoyage, exfoliation et masque nourrissant pour une peau purifiée et lumineuse.',
                    'Protocole soin complet avec massage relaxant du visage et du cou pour une détente totale.',
                ],
            ],
            [
                'name' => 'Massage Relaxant', 'category' => 'Massage', 'price' => 20000, 'duration' => 60, 'image' => '/images/services/massage.jpg',
                'descriptions' => [
                    'Un moment de détente profonde pour évacuer le stress et les tensions accumulées.',
                    'Massage corps complet aux huiles essentielles pour une relaxation totale et un bien-être durable.',
                    'Séance de massage qui soulage les tensions musculaires et apaise le mental en profondeur.',
                ],
            ],
            [
                'name' => 'Épilation Sourcils', 'category' => 'Épilation', 'price' => 4000, 'duration' => 15, 'image' => '/images/services/epilation.jpg',
                'descriptions' => [
                    'Épilation précise au fil ou à la cire pour un regard sublimé et des sourcils parfaitement dessinés.',
                    'Mise en forme et épilation des sourcils pour un regard défini et harmonieux.',
                    'Remodelage et épilation sur mesure selon votre morphologie de visage.',
                ],
            ],
            [
                'name' => 'Brushing', 'category' => 'Coiffure', 'price' => 5000, 'duration' => 30, 'image' => '/images/services/coiffure.jpg',
                'descriptions' => [
                    'Brushing volume et brillance pour une coiffure parfaite qui tient toute la journée.',
                    'Lissage et mise en forme professionnels pour un rendu lisse, brillant et durable.',
                    'Brushing personnalisé selon la nature de vos cheveux, avec produits coiffants adaptés.',
                ],
            ],
        ];

        foreach ($salonsData as $data) {
            $slug = Str::slug($data['name']);

            $manager = User::create([
                'name' => 'Manager '.$data['name'],
                'email' => $slug.'.manager@nadcel.test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            $salon = Salon::create([
                'manager_id' => $manager->id,
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'],
                'address' => $data['address'],
                'city' => $data['city'],
                'phone' => $data['phone'],
                'email' => 'contact.'.$slug.'@nadcel.test',
                'logo' => '/images/salons/'.$data['image'],
                'cover' => '/images/salons/'.$data['image'],
                'rating' => fake()->randomFloat(2, 3.5, 5),
            ]);

            $salon->verify();

            $services = collect($serviceCatalog)
                ->shuffle()
                ->take(fake()->numberBetween(4, 6))
                ->map(fn (array $svc) => Service::create([
                    'salon_id' => $salon->id,
                    'name' => $svc['name'],
                    'description' => fake()->randomElement($svc['descriptions']),
                    'price' => $svc['price'],
                    'duration' => $svc['duration'],
                    'category' => $svc['category'],
                    'image' => $svc['image'],
                    'is_active' => true,
                ]));

            $employees = collect(range(1, fake()->numberBetween(2, 3)))
                ->map(function () use ($salon) {
                    $user = User::factory()->create();

                    return Employee::create([
                        'user_id' => $user->id,
                        'salon_id' => $salon->id,
                        'name' => $user->name,
                        'phone' => fake()->phoneNumber(),
                    ]);
                });

            foreach ($employees as $employee) {
                $employee->services()->attach(
                    $services->random(min(3, $services->count()))->pluck('id')
                );
            }

            foreach (range(1, fake()->numberBetween(5, 8)) as $i) {
                $service = $services->random();
                $employee = $employees->random();
                $isPast = fake()->boolean(50);
                $scheduledAt = $isPast
                    ? fake()->dateTimeBetween('-2 months', '-1 days')
                    : fake()->dateTimeBetween('+1 days', '+2 months');

                Appointment::create([
                    'client_id' => $clients->random()->id,
                    'salon_id' => $salon->id,
                    'service_id' => $service->id,
                    'employee_id' => $employee->id,
                    'scheduled_at' => $scheduledAt,
                    'duration' => $service->duration,
                    'status' => $isPast
                        ? fake()->randomElement(['completed', 'cancelled'])
                        : fake()->randomElement(['pending', 'confirmed']),
                    'price' => $service->price,
                    'notes' => fake()->boolean(30) ? fake()->sentence() : null,
                ]);
            }
        }
    }
}
