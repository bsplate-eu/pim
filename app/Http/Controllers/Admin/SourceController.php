<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Source\IndexSourceRequest;
use App\Http\Requests\Admin\Source\CreateSourceRequest;
use App\Http\Requests\Admin\Source\StoreSourceRequest;
use App\Http\Requests\Admin\Source\EditSourceRequest;
use App\Http\Requests\Admin\Source\UpdateSourceRequest;
use App\Http\Requests\Admin\Source\DestroySourceRequest;
use App\Http\Requests\Admin\Source\BulkDestroySourceRequest;
use App\Models\Source;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SourceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexSourceRequest $request): Response | JsonResponse
    {
        $sourcesQuery = QueryBuilder::for(Source::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id','name','enabled'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id','name','enabled');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($sourcesQuery->select(['id'])->pluck('id'));
        }

        $sources = $sourcesQuery
            ->select('id','name','enabled')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('sources_url', $request->fullUrl());

        return Inertia::render('Source/Index', [
            'sources' => $sources,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateSourceRequest $request): Response
    {
        return Inertia::render('Source/Create', [
            
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSourceRequest $request): RedirectResponse
    {
        $source = Source::create($request->validated());

        return redirect()->route('crafter.sources.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditSourceRequest $request, Source $source): Response
    {
        return Inertia::render('Source/Edit', [
            'source' => $source,
            
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSourceRequest $request, Source $source): RedirectResponse
    {
        $source->update($request->validated());

        if (session('sources_url')) {
            return redirect(session('sources_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.sources.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroySourceRequest $request, Source $source): RedirectResponse
    {
        $source->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroySourceRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    Source::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                Source::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

}
