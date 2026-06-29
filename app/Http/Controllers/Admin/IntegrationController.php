<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integration\IndexIntegrationRequest;
use App\Http\Requests\Admin\Integration\CreateIntegrationRequest;
use App\Http\Requests\Admin\Integration\StoreIntegrationRequest;
use App\Http\Requests\Admin\Integration\EditIntegrationRequest;
use App\Http\Requests\Admin\Integration\UpdateIntegrationRequest;
use App\Http\Requests\Admin\Integration\DestroyIntegrationRequest;
use App\Http\Requests\Admin\Integration\BulkDestroyIntegrationRequest;
use App\Http\Requests\Admin\Pricelist\EditPricelistRequest;
use App\Http\Requests\Admin\Pricelist\UpdatePricelistRequest;
use App\Jobs\Connectors\BlogSyncJob;
use App\Jobs\Connectors\CatalogCreateJob;
use App\Jobs\Connectors\CatalogDeltaJob;
use App\Jobs\Connectors\MediaSyncJob;
use App\Jobs\SynchronizeIntegration;
use App\Models\Category;
use App\Models\Integration;
use App\Models\IntegrationCategory;
use App\Models\IntegrationConnectorRun;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSource;
use App\Models\Pricelist;
use App\Models\Source;
use App\Models\Template;
use App\Queries\Filters\FuzzyFilter;

use Google\Service\Docs\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class IntegrationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexIntegrationRequest $request): Response|JsonResponse
    {
        $integrationsQuery = QueryBuilder::for(Integration::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id',  'type', 'name', 'enabled'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id', 'type', 'name', 'enabled');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($integrationsQuery->select(['id'])->pluck('id'));
        }

        $integrations = $integrationsQuery
            ->with('integrationSources.source')
            ->select('id', 'type', 'name', 'enabled')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('integrations_url', $request->fullUrl());

        return Inertia::render('Integration/Index', [
            'integrations' => $integrations,
        ]);
    }

    private function getFormData(array $data = []): array
    {
        return array_merge([
            'typeOptions'      => collect(Integration::TYPES)->map(fn($value, $key) => ['value' => $key, 'label' => $value])->values(),
            'pricelistOptions' => Pricelist::all()->map(fn($model) => ['value' => $model->id, 'label' => "$model->name ($model->currency)"]),
            'templateOptions'  => Template::all()->map(fn($model) => ['value' => $model->id, 'label' => "$model->name ($model->locale)"]),
            'sourceOptions'    => Source::query()->orderBy('order')->orderBy('id')->get()->map(fn($model) => ['value' => $model->id, 'label' => "$model->name"]),
            'categoryOptions'  => Category::whereNull('parent_id')->get()->map(fn($model) => ['value' => $model->id, 'label' => "$model->name"]),
            // TODO: enable when Blog module exists (App\Models\Blog)
            // 'blogOptions'   => \App\Models\Blog::orderBy('name')->get()->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
            'blogOptions'      => [],
        ], $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateIntegrationRequest $request): Response
    {
        $integration = new Integration();
        $integration->setRelation('integrationSources', [new IntegrationSource]);

        return Inertia::render('Integration/Create', $this->getFormData(['integration' => $integration,]));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntegrationRequest $request): RedirectResponse
    {
        $integration = Integration::create($request->validated());

        $integration_sources = $request->validated('integration_sources');
        $integration->syncIntegrationSources($integration_sources);
        $integration->addAllEnabledProducts();

        $integration->generateApiData();

        return redirect()->route('crafter.integrations.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }



    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditIntegrationRequest $request, Integration $integration): Response
    {
        $integration->load('integrationSources');

        // Pole `key` jest domyślnie w $hidden (żeby nie wyciekało w listach/API);
        // w formularzu edycji admin musi je widzieć/edytować — odsłoń jawnie tylko tutaj.
        $integration->makeVisible('key');

        return Inertia::render('Integration/Edit', $this->getFormData([
            'integration' => $integration
        ]));
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIntegrationRequest $request, Integration $integration): RedirectResponse
    {

        $integration->update($request->validated());

        if($request->has('integration_sources')){
            $integration_sources = $request->validated('integration_sources');
            $integration->syncIntegrationSources($integration_sources);
            $integration->addAllEnabledProducts();
        }

        if (session('integrations_url')) {
            return redirect(session('integrations_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.integrations.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyIntegrationRequest $request, Integration $integration): RedirectResponse
    {
        $integration->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    public function sync(Integration $integration): RedirectResponse
    {
        Cache::forget('integrations:sync:stop-all');
        // Bez afterResponse() przy driverze `sync` cały sync (Presta/LC) leci w tym samym żądaniu HTTP → timeout / 500.
        SynchronizeIntegration::dispatch($integration->id)->afterResponse();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    public function syncConnector(Integration $integration, string $connector): RedirectResponse
    {
        // 1. Clear global stop flag
        Cache::forget('integrations:sync:stop-all');

        // 2. Release stale locks for this integration
        Cache::forget("integration:{$integration->id}:sync");

        if (!in_array($integration->type, ['prestashop', 'litecart'], true)) {
            return redirect()->back()->withErrors([
                'integration' => ___('crafter', 'Connector actions are available only for PrestaShop and LiteCart integrations.'),
            ]);
        }

        // 3. Clear payload hashes so delta re-evaluates everything
        IntegrationProduct::where('integration_id', $integration->id)->update(['payload_hash' => null]);
        IntegrationCategory::where('integration_id', $integration->id)->update(['payload_hash' => null]);

        // 4. Mark any stuck "running" connector runs as failed
        IntegrationConnectorRun::where('integration_id', $integration->id)
            ->whereIn('status', ['running', 'pending'])
            ->update([
                'status'      => 'failed',
                'message'     => 'Reset by new sync request.',
                'finished_at' => now(),
            ]);

        // 5. Mapuj akcję na nazwę connectora w runie
        $runConnector = match ($connector) {
            'catalog-create' => 'catalog_create',
            'catalog-delta'  => 'catalog_delta',
            'media'          => 'media',
            'blog'           => 'blog',
            default          => null,
        };

        if ($runConnector === null) {
            return redirect()->back()->withErrors([
                'integration' => ___('crafter', 'Unknown connector action.'),
            ]);
        }

        // 6. Utwórz wpis runu OD RAZU (status pending) — natychmiast widoczny w Status Sync.
        //    Worker przejmie ten run i zmieni status na running -> completed/failed.
        $run = IntegrationConnectorRun::create([
            'integration_id' => $integration->id,
            'connector'      => $runConnector,
            'status'         => 'pending',
            'trigger_type'   => 'manual',
        ]);

        // 7. Dispatch joba z id już utworzonego runu
        match ($connector) {
            'catalog-create' => CatalogCreateJob::dispatch($integration->id, 'manual', $run->id),
            'catalog-delta'  => CatalogDeltaJob::dispatch($integration->id, 'manual', $run->id),
            'media'          => MediaSyncJob::dispatch($integration->id, 'manual', $run->id),
            'blog'           => BlogSyncJob::dispatch($integration->id, 'manual', $run->id),
        };

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyIntegrationRequest $request): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    Integration::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                Integration::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

}
