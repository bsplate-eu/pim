<?php

namespace App\Sources;

use App\Models\Source;

class BaseSource
{

    public function __construct(protected Source $source)
    {
    }

}
