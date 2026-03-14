<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientPdfMealTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('seed-data/meal_templates.json')), true);

        foreach ($rows as $row) {
            DB::table('meal_templates')->updateOrInsert(
                [
                    'goal' => $row['goal'],
                    'day_number' => $row['day_number'],
                    'meal_type' => $row['meal_type'],
                    'meal_name' => $row['meal_name'],
                ],
                [
                    'source_pdf' => $row['source_pdf'],
                    'time' => $row['time'],
                    'instructions' => $row['instructions'],
                    'ingredients_json' => json_encode($row['ingredients']),
                    'calories' => $row['calories'],
                    'protein_g' => $row['protein_g'],
                    'carbs_g' => $row['carbs_g'],
                    'fat_g' => $row['fat_g'],
                    'cost_aed' => $row['cost_aed'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
