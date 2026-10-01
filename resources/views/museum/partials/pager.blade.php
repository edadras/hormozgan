@if ($paginator->hasPages())
<nav class="pager" aria-label="صفحه‌بندی">
  @if (!$paginator->onFirstPage())<a class="btn ghost small" href="{{ $paginator->previousPageUrl() }}">قبلی</a>@endif
  <span class="muted">صفحه {{ $paginator->currentPage() }} از {{ $paginator->lastPage() }}</span>
  @if ($paginator->hasMorePages())<a class="btn ghost small" href="{{ $paginator->nextPageUrl() }}">بعدی</a>@endif
</nav>
@endif
