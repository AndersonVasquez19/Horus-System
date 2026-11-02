<div class="space-y-1 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 break-words text-sm text-[#64748B]">
    <p class="font-semibold text-[#172B3A]">Total de coincidencias: {{ $matriculas->total() }}</p>
    <p>Registros incluidos: {{ $matriculas->count() }} · Rango {{ $matriculas->firstItem() ?? 0 }}–{{ $matriculas->lastItem() ?? 0 }}</p>
    <p>Lote {{ $matriculas->currentPage() }} de {{ $matriculas->lastPage() }} · Máximo {{ $limitePdf }} registros por PDF</p>
    <p>Búsqueda: {{ $filtros['busqueda'] ?? 'Sin búsqueda' }} · Estado: {{ isset($filtros['estado']) ? ucfirst($filtros['estado']) : 'Todos' }}</p>
    <p>Curso: {{ isset($cursoFiltro) ? $cursoFiltro->codigo.' · '.$cursoFiltro->nombre : 'Todos' }} · {{ !empty($filtros['eliminados']) ? 'Solo matrículas eliminadas' : 'Sin matrículas eliminadas' }}</p>
    @if ($matriculas->lastPage() > 1)
        <p class="mt-2 font-semibold text-amber-800">Este PDF incluye un solo lote. Descarga los demás lotes desde el panel de reportes para obtener todas las coincidencias.</p>
    @endif
</div>
