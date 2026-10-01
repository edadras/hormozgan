<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Only system data is seeded. Real museum content enters exclusively through
     * verified imports, the research pipeline, the admin panel or community submissions.
     */
    public function run(): void
    {
        $this->call(MuseumSystemSeeder::class);
    }
}
