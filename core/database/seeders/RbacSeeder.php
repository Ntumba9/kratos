<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Synchronises roles and permissions from config('kratos'). Idempotent: safe
 * to run on every deploy. Permissions removed from the catalogue are deleted.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogue = config('kratos.permissions');

            foreach ($catalogue as $slug => $description) {
                Permission::query()->updateOrCreate(['slug' => $slug], ['description' => $description]);
            }
            Permission::query()->whereNotIn('slug', array_keys($catalogue))->delete();

            $permissionIds = Permission::query()->pluck('id', 'slug');

            foreach (config('kratos.roles') as $slug => $definition) {
                $role = Role::query()->updateOrCreate(['slug' => $slug], [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                ]);

                $unknown = array_diff($definition['permissions'], array_keys($catalogue));
                if ($unknown !== []) {
                    throw new \LogicException("Role [{$slug}] references unknown permissions: ".implode(', ', $unknown));
                }

                $role->permissions()->sync($permissionIds->only($definition['permissions'])->values()->all());
            }
        });
    }
}
