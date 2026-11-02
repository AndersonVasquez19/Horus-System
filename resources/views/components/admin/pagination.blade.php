@props(['paginator'])

@if ($paginator->hasPages())
    <div class="admin-pagination">
        {{ $paginator->onEachSide(1)->links() }}
    </div>
@endif
