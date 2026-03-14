# Client meal-plan PDF seed package

This package was generated from these uploaded PDFs:

- gain.pdf
- loss.pdf
- normal.pdf
- meal-plan-2025-08-25 (1).pdf

## Included files

- `ingredients.json` - cleaned ingredient master list (39 records)
- `sauces.json` - cleaned sauce list (1 records)
- `meal_templates.json` - parsed meal templates (157 records)
- `ClientPdfIngredientSeeder.php`
- `ClientPdfSauceSeeder.php`
- `ClientPdfMealTemplateSeeder.php`

## Important notes

1. The older August 5 PDFs contain broken profile metrics (for example negative body-fat values and impossible BMR/TDEE values). I did **not** use those profile metrics as seed truth.
2. The ingredient lines in the PDFs only expose calories, protein, and AED cost at ingredient level. Carbs/fat are available reliably at the **meal total** level, not for every ingredient line.
3. The seeders assume the existence of these tables:
   - `ingredients`
   - `sauces`
   - `meal_templates`
4. If your column names differ, edit the seeder field names before running.

## Suggested install

Copy the JSON files into:

- `database/seed-data/ingredients.json`
- `database/seed-data/sauces.json`
- `database/seed-data/meal_templates.json`

Copy the PHP files into:

- `database/seeders/ClientPdfIngredientSeeder.php`
- `database/seeders/ClientPdfSauceSeeder.php`
- `database/seeders/ClientPdfMealTemplateSeeder.php`

Then call them from `DatabaseSeeder.php`.

## Recommended DatabaseSeeder.php snippet

```php
$this->call([
    ClientPdfIngredientSeeder::class,
    ClientPdfSauceSeeder::class,
    ClientPdfMealTemplateSeeder::class,
]);
```

## Suggested next cleanup

- normalize duplicated ingredient naming further if needed
- convert `ingredients_json` into a pivot table later if you want true relational meal templates
- prefer `meal-plan-2025-08-25 (1).pdf` as your best reference plan
