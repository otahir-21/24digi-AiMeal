<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientPdfIngredientSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('seed-data/ingredients.json')), true);

        foreach ($rows as $row) {
            DB::table('ingredients')->updateOrInsert(
                ['name' => $row['name']],
                [
                    'category' => $row['category'],
                    'default_quantity' => $row['default_quantity'],
                    'calories' => $row['calories'],
                    'protein_g' => $row['protein_g'],
                    'cost_aed' => $row['cost_aed'],
                    'notes' => $row['notes'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
