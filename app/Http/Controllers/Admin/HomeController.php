<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Hosting;
use App\Models\Integration;
use App\Models\Pricelist;
use App\Models\Product;
use App\Models\Scraper;
use App\Models\Source;
use App\Models\Template;
use App\Models\Title;
use App\Services\Charts\ProductCategoriesChart;
use App\Services\Charts\NewProductsChart;
use App\Services\Charts\TitleChart;
use App\Services\Ksef\DuePaymentsService;
use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Display the login view.
     *
     */
    public function index()
    {
        return redirect(app(GeneralSettings::class)->default_route);
    }
    public function dashboard(): Response
    {
        return Inertia::render('Home', [
            'counters' => $this->getCounters(),
            'charts' => $this->getCharts(),
            'duePayments' => app(DuePaymentsService::class)->forDashboard(),
        ]);
    }

    private function getCharts()
    {

        return [
            'categories' => ProductCategoriesChart::getLinks(),
            'products' => NewProductsChart::getDays(90),
        ];

    }

    private function getCounters()
    {
        return [
            'sources' => Source::count(),
            'integrations' => Integration::count(),
            'pricelists' => Pricelist::count(),
            'templates' => Template::count(),
            'products' => Product::count(),
        ];
    }

}
