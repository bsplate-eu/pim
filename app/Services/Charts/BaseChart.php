<?php

namespace App\Services\Charts;

class BaseChart
{
    protected static array $theme = [
        'mode' => 'light',
        'palette' => 'palette3',
        'monochrome' => [
            'enabled' => true,
            'color' => '#181B34',
            'shadeTo' => 'light',
            'shadeIntensity' => 0.9
        ]
    ];
    protected static array $animations = [
        'enabled' => true,
        'speed' => 1000,
        'animateGradually' => [
            'enabled' => true,
            'delay' => 100
        ],
        'dynamicAnimation' => [
            'enabled' => true,
            'speed' => 1000
        ]
    ];
}
