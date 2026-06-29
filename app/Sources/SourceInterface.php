<?php

namespace App\Sources;

use App\Models\Source;

interface SourceInterface
{

    public function synchronize();

}
