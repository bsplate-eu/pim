<?php

namespace App\Services\Charts;

use App\Models\Hosting;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductCategoriesChart extends BaseChart
{
    public static function getLinks()
    {
        $data = Product::select(
            'category',
            DB::raw('COUNT(*) AS count')
        )
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return [
            'series' => $data->pluck('count')->toArray(),
            'options' => [
                'theme' => self::$theme,
                'chart' => [
                    'animations' => self::$animations
                ],
                'labels' => $data->pluck('category')->toArray()
            ],
        ];
    }
}
