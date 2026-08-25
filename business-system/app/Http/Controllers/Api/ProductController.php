<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** 商品查询：SKU / 关键词 / 类目 / 状态 */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();
        $query->when($request->filled('sku'), fn ($q) => $q->where('sku', 'ilike', "%{$request->string('sku')}%"));
        $query->when($request->filled('keyword'), fn ($q) => $q->where('name', 'ilike', "%{$request->string('keyword')}%"));
        $query->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->orderBy('sku');
        $pageSize = min((int) $request->input('page_size', 20), 100);
        $paginator = $query->paginate($pageSize)->withQueryString();

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn (Product $p) => $this->format($p)),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    /** 商品详情（含库存） */
    public function show(Request $request, string $sku): JsonResponse
    {
        $product = Product::where('sku', $sku)->first();
        if (! $product) {
            return ApiResponse::fail(40403, '商品不存在', 404);
        }

        return ApiResponse::ok($this->format($product));
    }

    private function format(Product $product): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'price' => (float) $product->price,
            'currency' => $product->currency,
            'stock' => $product->stock,
            'weight_kg' => (float) $product->weight_kg,
            'status' => $product->status,
        ];
    }
}
