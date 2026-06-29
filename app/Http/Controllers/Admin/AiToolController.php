<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiTool\IndexAiToolRequest;
use App\Http\Requests\Admin\AiTool\CreateAiToolRequest;
use App\Http\Requests\Admin\AiTool\StoreAiToolRequest;
use App\Http\Requests\Admin\AiTool\EditAiToolRequest;
use App\Http\Requests\Admin\AiTool\UpdateAiToolRequest;
use App\Http\Requests\Admin\AiTool\DestroyAiToolRequest;
use App\Http\Requests\Admin\AiTool\BulkDestroyAiToolRequest;
use App\Models\AiTool;
use App\Queries\Filters\FuzzyFilter;
use App\Services\ChatGptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AiToolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexAiToolRequest $request): Response|JsonResponse
    {
        $aiToolsQuery = QueryBuilder::for(AiTool::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id', 'name', 'description', 'provider', 'config', 'enabled', 'order'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id', 'name', 'description', 'provider', 'config', 'enabled', 'order');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($aiToolsQuery->select(['id'])->pluck('id'));
        }

        $aiTools = $aiToolsQuery
            ->select('id', 'name', 'description', 'provider', 'config', 'enabled', 'order')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('aiTools_url', $request->fullUrl());

        return Inertia::render('AiTool/Index', [
            'aiTools' => $aiTools,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateAiToolRequest $request): Response
    {
        return Inertia::render('AiTool/Create', [

        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAiToolRequest $request): RedirectResponse
    {
        $aiTool = AiTool::create($request->validated());

        return redirect()->route('crafter.ai-tools.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditAiToolRequest $request, AiTool $aiTool): Response
    {
        return Inertia::render('AiTool/Edit', [
            'aiTool' => $aiTool,

        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAiToolRequest $request, AiTool $aiTool): RedirectResponse
    {
        $aiTool->update($request->validated());

        if (session('aiTools_url')) {
            return redirect(session('aiTools_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.ai-tools.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyAiToolRequest $request, AiTool $aiTool): RedirectResponse
    {
        $aiTool->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyAiToolRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    AiTool::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                AiTool::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    public function getTools()
    {
        return response()->json(AiTool::where('enabled', true)->get()->map(function ($tool) {
            return [
                'id' => $tool->id,
                'name' => $tool->name,
                'description' => $tool->description,
                'provider' => $tool->provider,
                'config' => $tool->config,
            ];
        }));
    }


    public function execute(Request $request)
    {

        try {
            $data = [];
            $ai_tool = $request->get('ai_tool');
            $product = $request->get('product');
            $current_locale = $request->get('current_locale');

            if($ai_tool['provider'] == 'openai') {
                $data = ChatGptService::generateProductContent($ai_tool['config'], $product, $current_locale);
            }


            return response()->json(['success' => true, 'data' => $data]);

        }catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }


    }

}
