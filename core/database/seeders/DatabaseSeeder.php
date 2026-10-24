<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe baseline: the RBAC catalogue and built-in detection rules. Demonstration data
     * is opt-in via `php artisan db:seed --class=DemoSeeder`.
     */
    public function run(): void
    {
        $this->call([RbacSeeder::class, DetectionRuleSeeder::class]);
    }
}
