<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Attribute\IndexAttributeRequest;
use App\Http\Requests\Admin\Attribute\CreateAttributeRequest;
use App\Http\Requests\Admin\Attribute\StoreAttributeRequest;
use App\Http\Requests\Admin\Attribute\EditAttributeRequest;
use App\Http\Requests\Admin\Attribute\UpdateAttributeRequest;
use App\Http\Requests\Admin\Attribute\DestroyAttributeRequest;
use App\Http\Requests\Admin\Attribute\BulkDestroyAttributeRequest;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AttributeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexAttributeRequest $request): Response|JsonResponse
    {
        $attributesQuery = QueryBuilder::for(Attribute::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id', 'name'
                )),
            ])
            ->defaultSort('order')
            ->allowedSorts('id', 'name', 'order');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($attributesQuery->select(['id'])->pluck('id'));
        }

        $attributes = $attributesQuery
            ->select('id', 'name', 'order')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('attributes_url', $request->fullUrl());

        return Inertia::render('Attribute/Index', [
            'attributes' => $attributes,
            'all_attributes' => ['data' => Attribute::orderBy('order')->get()],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateAttributeRequest $request): Response
    {
        return Inertia::render('Attribute/Create', [

        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAttributeRequest $request): RedirectResponse
    {
        $attribute = Attribute::create($request->validated());
        $attribute->storeValues($request->get('attribute_values'));

        return redirect()->route('crafter.attributes.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditAttributeRequest $request, Attribute $attribute): Response
    {
        return Inertia::render('Attribute/Edit', [
            'attribute' => $attribute->load('values'),
            'values' => $attribute->values,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttributeRequest $request, Attribute $attribute): RedirectResponse
    {
        $attribute->update($request->validated());
        $attribute->storeValues($request->get('attribute_values'));

        if (session('attributes_url')) {
            return redirect(session('attributes_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.attributes.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    public function updateOrder(Request $request)
    {

        collect($request->get('attributes'))->each(function ($attribute, $key) {
            Attribute::where('id', $attribute)->update(['order' => $key]);
        });

        return response()->json(['message' => ___('crafter', 'Operation successful')]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyAttributeRequest $request, Attribute $attribute): RedirectResponse
    {
        $attribute->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyAttributeRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    Attribute::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                Attribute::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

}
