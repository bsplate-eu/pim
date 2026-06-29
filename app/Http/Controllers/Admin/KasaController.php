<?php

namespace App\Http\Controllers\Admin;

use Inertia\Inertia;
use Inertia\Response;

class KasaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Kasa/Index');
    }
}
