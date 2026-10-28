<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'findcasa:create-admin {name} {email} {password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée explicitement un compte administrateur FindCasa';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        User::updateOrCreate(
            ['email' => $this->argument('email')],
            [
                'name' => $this->argument('name'),
                'password' => $this->argument('password'),
                'role' => 'admin',
                'owner_verification_status' => 'not_applicable',
            ],
        );

        $this->info('Compte administrateur créé ou mis à jour.');

        return self::SUCCESS;
    }
}
