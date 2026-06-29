<?php

namespace App\Http\Controllers\Admin\Media;

use App\Http\Requests\Admin\Media\IndexMedia;
use App\Http\Requests\Admin\Media\UpdateMedia;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class MediaController extends Controller
{
    public function index(IndexMedia $request)
    {
        $media = $this->queryMedia(Media::query());

        return Inertia::render('Media/Index', [
            'data' => $media,
            'filterOptions' => $this->getFilterOptions(),
        ]);
    }

    public function images(IndexMedia $request)
    {
        $media = $this->queryMedia(Media::where('mime_type', 'LIKE', '%image\/%'));

        return Inertia::render('Media/Index', [
            'data' => $media,
            'filterOptions' => $this->getFilterOptions([
                'mime_type' => null,
            ]),
        ]);
    }

    public function files(IndexMedia $request)
    {
        $media = $this->queryMedia(Media::whereNot('mime_type', 'LIKE', '%image\/%'));

        return Inertia::render('Media/Index', [
            'data' => $media,
            'filterOptions' => $this->getFilterOptions([
                'mime_type' => Media::whereNot('mime_type', 'LIKE', '%image\/%')->select('mime_type')->distinct()->pluck('mime_type'),
            ]),
        ]);
    }

    protected function queryMedia(Builder $query)
    {
        return QueryBuilder::for($query)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'model_type',
                    'collection_name',
                    'file_name',
                    'custom_properties->name'
                )),
                AllowedFilter::exact('model_type'),
                AllowedFilter::exact('collection_name'),
                AllowedFilter::exact('disk'),
                'mime_type',
            ])
            ->defaultSort('id')
            ->allowedSorts(['id', 'file_name', 'size', 'created_at', AllowedSort::field('title', 'custom_properties->name')])
            ->select(['id', 'model_type', 'model_id', 'collection_name', 'mime_type', 'disk', 'file_name', 'size', 'custom_properties', 'created_at'])
            ->paginate(request()->get('per_page'))->withQueryString();
    }

    protected function getFilterOptions(array $filterOptions = [])
    {
        return array_merge([
            'model_type' => Media::select('model_type')->distinct()->pluck('model_type'),
            'collection_name' => Media::select('collection_name')->distinct()->pluck('collection_name'),
            'disk' => Media::select('disk')->distinct()->pluck('disk'),
            'mime_type' => Media::select('mime_type')->distinct()->pluck('mime_type'),
        ], $filterOptions);
    }

    public function updateMedia(Media $media, UpdateMedia $request)
    {
        $media->update([
            'custom_properties' => $request->get('custom_properties'),
        ]);

        return redirect()->back()->with(['message' => ___('crafter', 'Media property updated')]);
    }

    public function zip(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:media,id'],
        ]);

        $mediaItems = Media::query()
            ->whereIn('id', $data['ids'])
            ->get();

        if ($mediaItems->isEmpty()) {
            return response()->json(['error' => 'No media found'], 404);
        }

        // 🔹 utwórz tymczasowy katalog
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        // 🔹 ścieżka do ZIP-a
        $zipPath = $tmpDir . '/media-' . now()->format('Ymd-His') . '.zip';

        // 🔹 utwórz archiwum
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Cannot create zip file'], 500);
        }

        foreach ($mediaItems as $media) {
            $path = $media->getPath();

            if (!File::exists($path)) {
                continue;
            }

            // nazwa w archiwum
            $fileName = ($media->collection_name ?: 'media') . '/' . $media->file_name;

            // pobierz zawartość pliku i dodaj do zipa
            $content = File::get($path);
            $zip->addFromString($fileName, $content);
        }

        $zip->close();

        // 🔹 zwróć plik jako pobierany
        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

}
