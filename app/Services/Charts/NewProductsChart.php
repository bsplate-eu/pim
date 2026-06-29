<?php

namespace App\Services\Charts;

use App\Models\Title;
use Illuminate\Support\Facades\DB;

class NewProductsChart extends BaseChart
{
    public static function getDays(int $days = 30)
    {
        $data = DB::table('products')
            ->join('sources', 'products.source_id', '=', 'sources.id')
            ->select(
                'sources.name as source_name',
                DB::raw('DATE(products.created_at) AS date'),
                DB::raw('COUNT(*) AS count')
            )
            ->where('products.created_at', '>=', now()->subDays($days)->startOfDay())
            ->groupBy('source_name', 'date')
            ->orderBy('date')
            ->get();


        $dates = $data->pluck('date')->unique()->sort()->values();
        $grouped = $data->groupBy('source_name');


        $series = [];
        foreach ($grouped as $scraperName => $entries) {
            $counts = [];
            foreach ($dates as $date) {
                $count = $entries->firstWhere('date', $date)->count ?? 0;
                $counts[] = $count;
            }
            $series[] = [
                'name' => $scraperName,
                'data' => $counts
            ];
        }

        return [
            'series' => $series,
            'options' => [
                'theme' => self::$theme,
                'chart' => [
                    'animations' => self::$animations
                ],
                'xaxis' => [
                    'categories' => $dates->toArray()
                ],
                'stroke' => [
                    'curve' => 'smooth'
                ]
            ],
        ];
    }


}
