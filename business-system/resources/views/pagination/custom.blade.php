@if ($paginator->hasPages())
    @php $paginator->onEachSide(2); @endphp
    {{-- 上一页 --}}
    @if ($paginator->onFirstPage())
        <span class="disabled">« 上一页</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}">« 上一页</a>
    @endif

    {{-- 页码（窗口模式：首尾 + 当前页前后 2 页，中间用省略号） --}}
    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="disabled">…</span>
        @else
            @foreach ($element as $page => $url)
                <a href="{{ $url }}" @class(['active' => $page == $paginator->currentPage()])>{{ $page }}</a>
            @endforeach
        @endif
    @endforeach

    {{-- 下一页 --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}">下一页 »</a>
    @else
        <span class="disabled">下一页 »</span>
    @endif
@endif
