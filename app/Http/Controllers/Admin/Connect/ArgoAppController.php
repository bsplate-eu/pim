<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Mail\Message;
use App\Models\WarehouseSheetRow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo App — panelowa strona apki na telefon (PWA pod /admin/m).
 *
 * Sama apka to osobne ekrany `Mobile/*`; ten ekran jest po to, zeby bylo skad
 * ja zainstalowac i widac, czy zyje. Dwa moduly: Magazyn i Poczta.
 *
 * Bez Google Play — instalacja idzie przez „Dodaj do ekranu glownego", wiec
 * adres musi byc pelny (do wpisania na telefonie), a nie wzgledny.
 */
class ArgoAppController extends Controller
{
    public function index(Request $request): Response
    {
        $sheet = WarehouseSheetRow::DEFAULT_SHEET;
        $warehouse = WarehouseSheetRow::where('sheet', $sheet);

        return Inertia::render('Connect/App/Index', [
            'appUrl' => route('crafter.mobile.home'),
            'modules' => [
                [
                    'key'    => 'warehouse',
                    'name'   => 'Magazyn',
                    'desc'   => 'Wyszukiwarka po kodzie: gdzie leży i ile jest.',
                    'count'  => (int) $warehouse->count(),
                    'unit'   => 'kodów',
                    'detail' => $warehouse->max('updated_at')
                        ? 'arkusz „'.$sheet.'" z '.$warehouse->max('updated_at')
                        : 'arkusz jeszcze nieimportowany',
                ],
                [
                    'key'    => 'mail',
                    'name'   => 'Poczta',
                    'desc'   => 'Wspólna skrzynka firmowa — czytanie i odpisywanie.',
                    'count'  => (int) Message::query()
                        ->where('is_trashed', false)->where('is_spam', false)->where('is_sent', false)
                        ->where('is_read', false)->count(),
                    'unit'   => 'nieprzeczytanych',
                    'detail' => 'ta sama skrzynka co Argo Mail na desktopie',
                ],
            ],
            // Ile telefonow ma wlaczone powiadomienia — jedyny sygnal, ze ktos
            // faktycznie zainstalowal apke, bo PWA nie melduje sie inaczej.
            'pushDevices' => Schema::hasTable('push_subscriptions')
                ? (int) DB::table('push_subscriptions')->count()
                : null,
        ]);
    }
}
