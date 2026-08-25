@extends('layouts.admin')

@section('title', '创建订单')
@section('page_sub', '手动下单（事务扣减库存）')

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="margin:0;">🛒 创建订单</h1>
        <a href="{{ url('/admin/orders') }}" class="reset">← 返回列表</a>
    </div>

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ url('/admin/orders') }}">
        @csrf

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div class="card" style="margin:0;">
                <h1 style="font-size:15px; margin-bottom:12px;">客户与币种</h1>
                <label style="display:block; font-size:13px; color:#374151; margin:8px 0 6px;">客户</label>
                <select name="customer_id" required style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:#fff;">
                    <option value="">请选择客户</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected((string) old('customer_id') === (string) $c->id)>
                            {{ $c->name }}（{{ $c->email }} · {{ $c->country }}）
                        </option>
                    @endforeach
                </select>

                <label style="display:block; font-size:13px; color:#374151; margin:14px 0 6px;">成交币种</label>
                <select name="currency" required style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:#fff;">
                    @foreach ($currencies as $cur)
                        <option value="{{ $cur }}" @selected(old('currency', 'USD') === $cur)>{{ $cur }}</option>
                    @endforeach
                </select>
                <small style="color:#9ca3af;">商品基准价 USD，按汇率换算为成交币种并保存快照</small>
            </div>

            <div class="card" style="margin:0;">
                <h1 style="font-size:15px; margin-bottom:12px;">收货地址</h1>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div><label style="font-size:12px; color:#374151;">收件人 *</label>
                        <input name="recipient_name" value="{{ old('recipient_name') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:12px; color:#374151;">电话 *</label>
                        <input name="phone" value="{{ old('phone') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:12px; color:#374151;">国家 *</label>
                        <input name="country" value="{{ old('country') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:12px; color:#374151;">州/省</label>
                        <input name="state" value="{{ old('state') }}" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:12px; color:#374151;">城市 *</label>
                        <input name="city" value="{{ old('city') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div><label style="font-size:12px; color:#374151;">邮编 *</label>
                        <input name="postal_code" value="{{ old('postal_code') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                    <div style="grid-column:1 / -1;"><label style="font-size:12px; color:#374151;">详细地址 *</label>
                        <input name="address_line1" value="{{ old('address_line1') }}" required style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <h1 style="font-size:15px; margin-bottom:12px;">商品明细</h1>
            <div id="items">
                <div class="item-row" style="display:flex; gap:8px; margin-bottom:8px;">
                    <select name="items[0][sku]" required style="flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff;">
                        <option value="">选择商品</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->sku }}">{{ $p->sku }} — {{ $p->name }}（${{ $p->price }} · 库存 {{ $p->stock }}）</option>
                        @endforeach
                    </select>
                    <input type="number" name="items[0][quantity]" value="1" min="1" required
                           style="width:90px; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    <button type="button" onclick="removeRow(this)" style="background:#dc2626; padding:8px 14px;">删除</button>
                </div>
            </div>
            <button type="button" onclick="addRow()" style="background:#6b7280;">+ 添加商品</button>
        </div>

        <button type="submit" style="padding:12px 32px; font-size:15px;">提交订单</button>
    </form>

    <script>
        let rowIndex = 1;
        const productOptions = @json($products->map(fn ($p) => ['sku' => $p->sku, 'label' => $p->sku.' — '.$p->name.'（$'.$p->price.' · 库存 '.$p->stock.'）']));

        function addRow() {
            const wrap = document.getElementById('items');
            const row = document.createElement('div');
            row.className = 'item-row';
            row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px;';
            let opts = '<option value="">选择商品</option>';
            productOptions.forEach(p => { opts += `<option value="${p.sku}">${p.label}</option>`; });
            row.innerHTML = `
                <select name="items[${rowIndex}][sku]" required style="flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff;">${opts}</select>
                <input type="number" name="items[${rowIndex}][quantity]" value="1" min="1" required style="width:90px; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                <button type="button" onclick="removeRow(this)" style="background:#dc2626; padding:8px 14px;">删除</button>`;
            wrap.appendChild(row);
            rowIndex++;
        }

        function removeRow(btn) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length <= 1) { alert('至少保留一个商品'); return; }
            btn.closest('.item-row').remove();
        }
    </script>
@endsection
