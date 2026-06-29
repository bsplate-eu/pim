<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Template\IndexTemplateRequest;
use App\Http\Requests\Admin\Template\CreateTemplateRequest;
use App\Http\Requests\Admin\Template\StoreTemplateRequest;
use App\Http\Requests\Admin\Template\EditTemplateRequest;
use App\Http\Requests\Admin\Template\UpdateTemplateRequest;
use App\Http\Requests\Admin\Template\DestroyTemplateRequest;
use App\Http\Requests\Admin\Template\BulkDestroyTemplateRequest;
use App\Models\Product;
use App\Models\ProductOld;
use App\Models\Template;
use App\Queries\Filters\FuzzyFilter;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexTemplateRequest $request): Response | JsonResponse
    {
        $templatesQuery = QueryBuilder::for(Template::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id','locale','name','title','description'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id','locale','name','title','description');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($templatesQuery->select(['id'])->pluck('id'));
        }

        $templates = $templatesQuery
            ->select('id','locale','name','title','description')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('templates_url', $request->fullUrl());

        return Inertia::render('Template/Index', [
            'templates' => $templates,
        ]);
    }

    private function getFormData(array $data = []): array{
        return array_merge([
            'template' => new Template(),
            'available_locales' => app(GeneralSettings::class)->available_locales,
            'available_variables' => implode(', ',array_keys((new Product)->getVariables())),
        ], $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateTemplateRequest $request): Response
    {
        return Inertia::render('Template/Create', $this->getFormData());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTemplateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']);
        $template = Template::create($data);

        return redirect()->route('crafter.templates.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditTemplateRequest $request, Template $template): Response
    {

        return Inertia::render('Template/Edit', $this->getFormData(['template' => $template]));
    }

    public function preview(EditTemplateRequest $request, Template $template)
    {
        app()->setLocale($template->locale);
        $product = Product::query()->inRandomOrder()->take(1)->first();
        $title = $template->getRenderedTitle($product);
        $description = $template->getRenderedDescription($product);

        return Inertia::render('Template/Preview', compact('template','title', 'description'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTemplateRequest $request, Template $template): RedirectResponse
    {
        $template->update($request->validated());

        if (session('templates_url')) {
            return redirect(session('templates_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.templates.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyTemplateRequest $request, Template $template): RedirectResponse
    {
        $template->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyTemplateRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    Template::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                Template::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

}
