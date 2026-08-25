<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\View\View;

class RateController extends Controller
{
    public function index(): View
    {
        $rates = ExchangeRate::orderBy('currency')->get();

        return view('admin.rates', ['rates' => $rates]);
    }
}
