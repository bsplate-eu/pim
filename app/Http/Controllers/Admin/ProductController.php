<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\ExportProductRequest;
use App\Http\Requests\Admin\Product\ImportProductRequest;
use App\Http\Requests\Admin\Product\IndexProductRequest;
use App\Http\Requests\Admin\Product\CreateProductRequest;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\EditProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Requests\Admin\Product\DestroyProductRequest;
use App\Http\Requests\Admin\Product\BulkDestroyProductRequest;
use App\Imports\ProductsImport;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Source;
use App\Queries\Filters\FuzzyFilter;
use App\Services\ChatGptService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Admin\ProductsExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexProductRequest $request): Response|JsonResponse
    {
        $productsQuery = QueryBuilder::for(Product::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id', 'external_id', 'category', 'name', 'product_code',
                )),
                AllowedFilter::callback('source', fn(Builder $query, $value) => $query->where('source_id', $value)),
                AllowedFilter::callback('enabled', fn(Builder $query, $value) => $query->where('enabled', $value)),
            ])
            ->defaultSort('id')
            ->allowedSorts('id', 'external_id', 'source_id', 'category', 'name', 'product_code', 'enabled')
            ->with(['source', 'categories', 'media' => function ($query) {
                $query->orderBy('order_column');
            }]);

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($productsQuery->select(['id'])->pluck('id'));
        }

        $products = $productsQuery
            ->select('id', 'external_id', 'source_id', 'category', 'name', 'product_code', 'enabled')
            ->paginate($request->get('per_page'))
            ->withQueryString();

        $products->getCollection()->transform(function ($product) {
            $product->setTranslation('name', app()->getLocale(), htmlspecialchars_decode($product->name));
            $product->thumbnail_url = $product->media?->first()?->getUrl('preview') ?? 'https://placehold.co/100';
            return $product;
        });


        Session::put('products_url', $request->fullUrl());

        return Inertia::render('Product/Index', [
            'products' => $products,
            'sources' => Source::query()->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateProductRequest $request): Response
    {
        $product = new Product();

        Pricelist::whereNotIn('id', $product->pricelists->pluck('id')->toArray())->get()->each(function ($pricelist) use (&$product) {
            $pricelist->pivot = ['price' => 0, 'pricelist_id' => $pricelist->id];
            $product->pricelists->push($pricelist);
        });

        return Inertia::render('Product/Create', [
            'product' => $product,
            'attributes' => Attribute::with('values')->orderBy('order')->get(),
            'categories' => Category::toTreeSelect(),
            'sources' => Source::query()->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());
        $product->storeAttributes($request->get('attribute_values'));
        $product->categories()->sync($request->get('category_ids', []));
        $pricelists = collect($request->get('pricelists', []))->pluck('pivot')->map(function ($p) use ($product) {
            $p['product_id'] = $product->id;
            return $p;
        });

        PricelistProduct::upsert($pricelists->toArray(), ['pricelist_id', 'product_id'], ['price']);

        return redirect()->route('crafter.products.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditProductRequest $request, Product $product): Response
    {
        $product->load('media', 'attributeValues', 'categories', 'pricelists');
        $attributes = Attribute::with('values')->orderBy('order')->get();
        $attributeValues = $attributes->mapWithKeys(fn($i) => [$i->slug => $product->attributeValues->where('attribute_id', $i->id)->pluck('id')->values()->toArray()]);
        $product->setRelation('attributeValues', $attributeValues);
        $product->category_ids = $product->categories->pluck('id')->toArray();
        $product->setTranslations('name', array_map(fn($i) => htmlspecialchars_decode($i), $product->getTranslations('name')));

        Pricelist::whereNotIn('id', $product->pricelists->pluck('id')->toArray())->get()->each(function ($pricelist) use (&$product) {
            $pricelist->pivot = ['product_id' => $product->id, 'pricelist_id' => $pricelist->id, 'price' => 0];
            $product->pricelists->push($pricelist);
        });

        return Inertia::render('Product/Edit', [
            'product' => $product,
            'attributes' => $attributes,
            'categories' => Category::toTreeSelect(),
            'sources' => Source::query()->get(),
        ]);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {

        $product->update($request->validated());

        if($request->filled('external_id')){
            $product->storeAttributes($request->get('attribute_values'));
            $product->categories()->sync($request->get('category_ids', []));
            $pricelists = collect($request->get('pricelists', []))->pluck('pivot');
            PricelistProduct::upsert($pricelists->toArray(), ['pricelist_id', 'product_id'], ['price']);
        }

        if (session('products_url')) {
            return redirect(session('products_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.products.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyProductRequest $request, Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyProductRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    Product::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                Product::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Export
     */
    public function exportImport()
    {

        return Inertia::render('Product/Export', [
            'available_locales' => app(GeneralSettings::class)->available_locales,
            'sources' => Source::query()->get(),
        ]);
    }

    public function export(ExportProductRequest $request)
    {

        return Excel::download(new ProductsExport($request->only(['locale', 'source_id'])), 'Products-' . now()->format("dmYHi") . '.xlsx');

    }

    public function import(ImportProductRequest $request)
    {

        $path = $request->get('files')[0]['path'];
        $mediumFileFullPath = Storage::disk('uploads')->path($path);

        Excel::import(new ProductsImport($request->only(['locale'])), $mediumFileFullPath);

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);

    }
}
