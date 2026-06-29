<?php

namespace App\Models;

use App\Media\AutoProcessMediaTrait;
use App\Media\InteractsWithMedia;
use App\Media\ProcessMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

class UnassignedMedia extends Model implements HasMedia
{
    use InteractsWithMedia;
    use ProcessMediaTrait;
    use AutoProcessMediaTrait;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('default')->singleFile();
    }
}
