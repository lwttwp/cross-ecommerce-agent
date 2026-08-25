<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::query();

        $query->when($request->filled('keyword'), function ($q) use ($request) {
            $k = $request->string('keyword');
            $q->where(function ($qq) use ($k) {
                $qq->where('sku', 'ilike', "%{$k}%")
                    ->orWhere('name', 'ilike', "%{$k}%")
                    ->orWhere('category', 'ilike', "%{$k}%");
            });
        });
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('low_stock'), fn ($q) => $q->where('stock', '<=', (int) $request->input('low_stock')));

        $query->orderByDesc('created_at');

        $products = $query->paginate(15)->withQueryString();

        return view('admin.products', ['products' => $products]);
    }
}
