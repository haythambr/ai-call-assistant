@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="pager-btn is-disabled"><x-icon name="back" /> Previous</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="back" /> Previous</a>
        @endif

        <span class="pager-info">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Next <x-icon name="arrow" /></a>
        @else
            <span class="pager-btn is-disabled">Next <x-icon name="arrow" /></span>
        @endif
    </nav>
@endif
