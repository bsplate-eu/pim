@php echo "<?php"
@endphp


namespace {{$modelNamespace}};

use Illuminate\Database\Eloquent\Model;
@if($isSoftDelete){{$softDeletesNamespace}}@endif
@foreach($relations->unique('type') as $relation)
use Illuminate\Database\Eloquent\Relations\{{ ucfirst($relation['type']) }};
@endforeach
@if(count($translatableColumns) > 0)use Spatie\Translatable\HasTranslations;
@endif
@if($hasMediaCollections)
use App\Media\ProcessMediaTrait;
use App\Media\AutoProcessMediaTrait;
use App\Media\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;
@endif
@if($hasImageCollections)
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Media\HasMediaPreviewsTrait;
@endif

class {{$modelName}} extends Model @if($hasMediaCollections) implements HasMedia @endif
{

@if($isSoftDelete)
    {{$softDeletes}}
@endif
@if(count($translatableColumns) > 0)
    use HasTranslations;
@endif
@if($hasMediaCollections)
    use ProcessMediaTrait;
    use AutoProcessMediaTrait;
    use InteractsWithMedia;
@endif
@if($hasImageCollections)
    use HasMediaPreviewsTrait;
@endif

    protected $table = '{{$tableName}}';
    protected $fillable = [{!! $fillable !!}];
@if(count($translatableColumns) > 0)

    public $translatable = [{!! $translatable !!}];
@endif
@if($modelRelations)
{!! $modelRelations !!}
@endif
@if($hasMediaCollections)

    public function registerMediaCollections(): void
    {
@foreach($mediaCollections as $collection)
        $this->addMediaCollection('{{$collection}}');
@endforeach
    }
@endif
@if($hasImageCollections)

    public function registerMediaConversions(Media $media = null): void
    {
        $this->autoRegisterPreviews();
    }
@endif
}
