@props(['expenses', 'compact' => false])
<div class="table-scroll"><table class="data-table">
    <thead><tr><th>Despesa / fornecedor</th><th>Data</th><th>Valor original</th><th>Valor em reais</th><th>Status</th><th><span class="sr-only">Detalhes</span></th></tr></thead>
    <tbody>
    @forelse($expenses as $expense)
    <tr>
        <td><a href="{{ route('expenses.show', $expense) }}" class="expense-cell"><span class="expense-icon"><x-icon name="receipt" :size="19"/></span><span><strong>{{ $expense->description }}</strong><small>{{ $expense->supplier }}</small></span></a></td>
        <td class="nowrap muted">{{ \Carbon\Carbon::parse($expense->expense_date)->format('d/m/Y') }}</td>
        <td class="nowrap"><x-money :value="$expense->amount_minor" :currency="$expense->currency"/><small class="table-sub">{{ $expense->currency }}</small></td>
        <td class="nowrap">@if($expense->status === 'converted')<x-money :value="$expense->brl_amount_minor"/>@else<span class="muted">—</span>@endif</td>
        <td><x-status :status="$expense->status"/></td>
        <td><a class="icon-button" href="{{ route('expenses.show', $expense) }}" aria-label="Ver despesa {{ $expense->description }}"><x-icon name="chevron" :size="16"/></a></td>
    </tr>
    @empty
    <tr><td colspan="6"><div class="empty-state"><span class="empty-icon"><x-icon name="receipt" :size="28"/></span><h3>Nenhuma despesa por aqui</h3><p>Registre uma despesa ou ajuste os filtros para encontrar o que procura.</p><a class="button button-secondary button-small" href="{{ route('expenses.create') }}"><x-icon name="plus" :size="16"/>Nova despesa</a></div></td></tr>
    @endforelse
    </tbody>
</table></div>
