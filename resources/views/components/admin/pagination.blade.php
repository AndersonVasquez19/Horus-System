@props(['paginator'])

@if ($paginator->hasPages())
    <div class="mt-5">
        {{ $paginator->onEachSide(1)->links() }}
    </div>
@endif
