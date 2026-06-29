<?php

namespace App\Http\Controllers\Admin;

use Inertia\Inertia;
use Inertia\Response;

class CostPlannerReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('CostPlanner/Reports');
    }
}
