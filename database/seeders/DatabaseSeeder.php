<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Dados de demonstração só são criados em local/testing.');

            return;
        }
        User::firstOrCreate(['email' => env('DEMO_EMAIL', 'demo@gridmidia.local')], [
            'name' => 'Alex Morgan', 'password' => env('DEMO_PASSWORD', 'Grid@2026!'),
        ]);
        collect([
            ['name' => 'Corporativo', 'company' => 'Grupo Gridmidia', 'slug' => 'corporativo'],
            ['name' => 'FDR', 'company' => 'Gridmidia Publishing', 'slug' => 'fdr'],
            ['name' => 'AdSeleto', 'company' => 'Gridmidia Tecnologia', 'slug' => 'adseleto'],
            ['name' => 'InCred', 'company' => 'Gridmidia Performance', 'slug' => 'incred'],
            ['name' => 'Garagem360', 'company' => 'Gridmidia Publishing', 'slug' => 'garagem360'],
        ])->map(fn ($unit) => Unit::firstOrCreate(['slug' => $unit['slug']], $unit));
    }
}
