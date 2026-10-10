@props(['paginator'])
@if($paginator->total() > 0)
<div class="pagination">
    <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }} {{ $paginator->total() === 1 ? 'registro' : 'registros' }}</span>
    <nav aria-label="Paginação" class="pagination-links">
        @if($paginator->onFirstPage())<span class="page-button disabled" aria-disabled="true"><x-icon name="arrow-left" :size="16"/></span>@else<a class="page-button" href="{{ $paginator->previousPageUrl() }}" aria-label="Página anterior"><x-icon name="arrow-left" :size="16"/></a>@endif
        <span class="page-current">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if($paginator->hasMorePages())<a class="page-button" href="{{ $paginator->nextPageUrl() }}" aria-label="Próxima página"><x-icon name="arrow-right" :size="16"/></a>@else<span class="page-button disabled" aria-disabled="true"><x-icon name="arrow-right" :size="16"/></span>@endif
    </nav>
</div>
@endif
