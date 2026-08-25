@if ($paginator->hasPages())
    {{-- 上一页 --}}
    @if ($paginator->onFirstPage())
        <span class="disabled">« 上一页</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}">« 上一页</a>
    @endif

    {{-- 页码 --}}
    @foreach ($paginator->getUrlRange(1, $paginator->lastPage()) as $page => $url)
        <a href="{{ $url }}" @class(['active' => $page == $paginator->currentPage()])>{{ $page }}</a>
    @endforeach

    {{-- 下一页 --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}">下一页 »</a>
    @else
        <span class="disabled">下一页 »</span>
    @endif
@endif
