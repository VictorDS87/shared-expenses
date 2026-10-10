<?php

namespace Database\Seeders;

use App\Actions\CreateExpense;
use App\Models\Expense;
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
        $units = collect([
            ['name' => 'Corporativo', 'company' => 'Grupo Gridmidia', 'slug' => 'corporativo'],
            ['name' => 'FDR', 'company' => 'Gridmidia Publishing', 'slug' => 'fdr'],
            ['name' => 'AdSeleto', 'company' => 'Gridmidia Tecnologia', 'slug' => 'adseleto'],
            ['name' => 'InCred', 'company' => 'Gridmidia Performance', 'slug' => 'incred'],
            ['name' => 'Garagem360', 'company' => 'Gridmidia Publishing', 'slug' => 'garagem360'],
        ])->map(fn ($unit) => Unit::firstOrCreate(['slug' => $unit['slug']], $unit));
        if (Expense::query()->exists()) {
            return;
        }
        // Fictional BRL-only data: the demo never manufactures an exchange quote.
        $examples = [
            ['Infraestrutura em nuvem', 'Cloud Services', '4280.90', 1],
            ['Licenças de colaboração', 'Workspace', '1850.00', 2],
            ['Plataforma de CRM', 'CRM Solutions', '2490.00', 3],
            ['Monitoramento de aplicações', 'Observabilidade', '780.50', 4],
            ['Consultoria de tecnologia', 'Tech Partners', '5600.00', 5],
            ['Ferramentas de design', 'Design Studio', '649.90', 6],
            ['Serviços de conectividade', 'Conecta', '1299.00', 7],
            ['Treinamento do time', 'Learning Hub', '2150.00', 8],
        ];
        $create = app(CreateExpense::class);
        foreach ($examples as [$description, $supplier, $amount, $days]) {
            $create->handle([
                'description' => $description, 'supplier' => $supplier,
                'date' => now()->startOfMonth()->addDays(min($days, now()->day - 1))->toDateString(),
                'amount' => $amount, 'currency' => 'BRL',
                'allocations' => $units->map(fn (Unit $unit, $index) => [
                    'unit_id' => $unit->id, 'percentage' => ['30', '25', '20', '15', '10'][$index],
                ])->all(),
            ]);
        }
    }
}
