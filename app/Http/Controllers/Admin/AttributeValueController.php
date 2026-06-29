<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttributeValue\IndexAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\CreateAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\StoreAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\EditAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\UpdateAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\DestroyAttributeValueRequest;
use App\Http\Requests\Admin\AttributeValue\BulkDestroyAttributeValueRequest;
use App\Models\AttributeValue;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AttributeValueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexAttributeValueRequest $request): Response | JsonResponse
    {
        $attributeValuesQuery = QueryBuilder::for(AttributeValue::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id','attribute_id','name'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id','attribute_id','name');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($attributeValuesQuery->select(['id'])->pluck('id'));
        }

        $attributeValues = $attributeValuesQuery
            ->select('id','attribute_id','name')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('attributeValues_url', $request->fullUrl());

        return Inertia::render('AttributeValue/Index', [
            'attributeValues' => $attributeValues,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateAttributeValueRequest $request): Response
    {
        return Inertia::render('AttributeValue/Create', [
            
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAttributeValueRequest $request): RedirectResponse
    {
        $attributeValue = AttributeValue::create($request->validated());

        return redirect()->route('crafter.attribute-values.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditAttributeValueRequest $request, AttributeValue $attributeValue): Response
    {
        return Inertia::render('AttributeValue/Edit', [
            'attributeValue' => $attributeValue,
            
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttributeValueRequest $request, AttributeValue $attributeValue): RedirectResponse
    {
        $attributeValue->update($request->validated());

        if (session('attributeValues_url')) {
            return redirect(session('attributeValues_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.attribute-values.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyAttributeValueRequest $request, AttributeValue $attributeValue): RedirectResponse
    {
        $attributeValue->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyAttributeValueRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    AttributeValue::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                AttributeValue::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

}
