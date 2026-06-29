<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImportNewMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Media::query()->get()->each(function (Media $media) {
            $new_file_name = str_replace('.png', '.jpg', $media->file_name);
            $new_file_name = str_replace('.jpg', '-Photoroom.jpg', $new_file_name);
            $new_path = public_path('new_media/' . $new_file_name);
            $old_path = public_path('media/' . $media->getPathRelativeToRoot());
            dump($new_path, File::exists($new_path));
            if(File::exists($new_path)){

                if(str_contains($media->file_name, '.png')){

                    Storage::disk('media')
                        ->put(str_replace('.png', '.jpg', $media->getPathRelativeToRoot()), File::get($new_path));
                    Storage::disk('media')
                        ->delete($old_path);

                    $media->file_name = str_replace('.png', '.jpg', $media->file_name);
                    $media->mime_type = 'image/jpeg';

                }else{
                    Storage::disk('media')
                        ->put($media->getPathRelativeToRoot(), File::get($new_path));
                }

                $media->size = File::size($new_path);
                $media->save();
            }
        });
    }
}
