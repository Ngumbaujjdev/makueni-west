<?php

namespace Database\Seeders;

use App\Models\Church;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GatheringTypeStarterSeeder extends Seeder
{
    /**
     * Seeds a starting list of gathering types for every existing church,
     * from the examples the user gave directly (Kesha, Tuesday Fellowship,
     * etc.) - a starting point each church then edits/extends/deactivates
     * for itself via church/attendance/gathering-types.php. Churches
     * created after this seeder runs start with an empty list (see the
     * gathering-types-config plan's "Explicitly out of scope").
     *
     * Idempotent - the (territory_id, slug) unique constraint on
     * gathering_types means a re-run just skips existing rows.
     */
    private const DEFAULTS = [
        'ministry_gathering' => [
            'Kesha (All-Night Prayer)',
            'Tuesday Fellowship',
            'Youth Service',
            'Choir Practice',
        ],
        'special_event' => [
            'Baptism Service',
            'Confirmation Service',
            'Crusade',
            'Church Dedication',
        ],
    ];

    public function run(): void
    {
        $this->command->info('🌱 SEEDING STARTER GATHERING TYPES PER CHURCH');
        $this->command->info(str_repeat('=', 70));

        $categoryIds = GatheringCategory::whereIn('slug', array_keys(self::DEFAULTS))
            ->pluck('id', 'slug');

        if ($categoryIds->count() !== count(self::DEFAULTS)) {
            $this->command->error('   ❌ Expected gathering categories not found - run the gathering_categories migration first.');

            return;
        }

        $churches = Church::all();
        $created = 0;
        $order = 0;

        foreach ($churches as $church) {
            foreach (self::DEFAULTS as $slug => $names) {
                $categoryId = $categoryIds[$slug];

                foreach ($names as $name) {
                    $typeSlug = Str::slug($name);

                    $exists = GatheringType::where('territory_id', $church->id)
                        ->where('slug', $typeSlug)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    GatheringType::create([
                        'gathering_category_id' => $categoryId,
                        'territory_id' => $church->id,
                        'name' => $name,
                        'slug' => $typeSlug,
                        'display_order' => $order++,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        }

        $this->command->info("   ✅ {$created} gathering type row(s) created across {$churches->count()} church(es).");
        $this->command->info('');
        $this->command->info('✅ Starter gathering types seeded.');
    }
}
