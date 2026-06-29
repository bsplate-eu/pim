<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

class GeneratePermissionTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crafter:generate-permission-translations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate permission translations';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (! File::exists(resource_path('translations'))) {
            File::makeDirectory(resource_path('translations'));
        }

        if (! File::exists(resource_path('translations/permissions'))) {
            File::makeDirectory(resource_path('translations/permissions'));
        }

        $permissions = collect(Permission::all()->map->name)->mapWithKeys(function ($permission) {
            $name = "";
            $permissionName = collect();

            collect(explode(".", $permission))->each(function ($value, $key) use (&$name, &$permissionName) {
                $name .= $key == 0 ? $value : ".$value";

                $permissionName->put($name, $name);
            });

            return $permissionName->all();
        })->toJson();

        File::put(resource_path('translations/permissions') . '/permission_translations.json', $permissions);

        return Command::SUCCESS;
    }
}
