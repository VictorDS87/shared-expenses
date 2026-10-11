@extends('layouts.app')

@section('title', 'Resultado da importação')

@section('breadcrumb', 'Resultado da importação')

@section('content')

<a href="{{ route('imports.index') }}" class="back-link"><x-icon name="arrow-left" :size="16"/>Voltar para importações</a>

<div class="page-heading"><div><div class="eyebrow">IMPORTAÇÃO #{{ str_pad($import->id, 4, '0', STR_PAD_LEFT) }}</div><h1>{{ $import->status === 'completed' ? 'Arquivo processado' : 'Importação interrompida' }}<span class="orange-dot">.</span></h1><p>{{ $import->original_name }} <span class="text-separator">·</span> {{ $import->created_at->format('d/m/Y \à\s H:i') }}</p></div><a href="{{ route('expenses.index') }}" class="button button-primary">Ver despesas<x-icon name="arrow-right" :size="17"/></a></div>

@if($import->status !== 'completed')

<div class="notice notice-info"><span>Processamento pendente: {{ $import->total_rows }} de {{ $import->expected_rows }} linhas confirmadas. Retomar preserva as despesas já registradas.</span></div>

<form method="POST" action="{{ route('imports.resume', $import) }}">@csrf<button class="button button-primary">Retomar importação</button></form>

@endif

@if($import->original_import_id)
<div class="notice notice-info"><span>Arquivo repetido. As linhas já importadas foram ignoradas neste envio. Os erros abaixo foram preservados, sem nova validação. <a href="{{ route('imports.show', $import->original_import_id) }}" class="text-link">Ver importação original #{{ $import->original_import_id }}</a>.</span></div>
@endif

<div class="stats-grid"><article class="stat-card"><div class="stat-label">Linhas encontradas<span class="stat-icon stat-icon-blue"><x-icon name="file"/></span></div><div class="stat-value">{{ $import->expected_rows ?: $import->total_rows }}</div><div class="stat-footer">Cabeçalho não incluído</div></article><article class="stat-card"><div class="stat-label">Importadas com sucesso<span class="stat-icon stat-icon-green"><x-icon name="check-circle"/></span></div><div class="stat-value text-success">{{ $import->imported_rows }}</div><div class="stat-footer">Despesas registradas; USD aguardam câmbio</div></article><article class="stat-card"><div class="stat-label">Ignoradas<span class="stat-icon stat-icon-blue"><x-icon name="file"/></span></div><div class="stat-value" data-ignored-rows>{{ $import->ignored_rows }}</div><div class="stat-footer">Já importadas em um envio anterior</div></article><article class="stat-card"><div class="stat-label">Linhas com erro<span class="stat-icon stat-icon-amber"><x-icon name="alert"/></span></div><div class="stat-value {{ $import->failed_rows ? 'text-danger' : '' }}">{{ $import->failed_rows }}</div><div class="stat-footer">Não geraram despesas</div></article></div>

<section class="panel">
    <div class="panel-heading"><div><h2>Despesas registradas pelo arquivo</h2><p>Cada linha aceita criou uma despesa com os rateios informados. BRL entra nos relatórios imediatamente; USD aguarda a conversão.</p></div></div>
    @if(count($import->successful_rows ?? []))
    <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Linha do CSV</th><th>Despesa / fornecedor</th><th>Data</th><th>Valor original</th><th>Status atual</th><th>Registro</th></tr></thead>
        <tbody>@foreach($import->successful_rows as $row)
            @php($expense = $expenses->get($row['expense_id']))
            <tr><td>Linha {{ $row['line'] }}</td>
            @if($expense)
            <td><strong>{{ $expense->description }}</strong><small class="table-sub">{{ $expense->supplier }}</small></td>
            <td>{{ $expense->expense_date->format('d/m/Y') }}</td>
            <td class="nowrap"><x-money :value="$expense->amount_minor" :currency="$expense->currency"/></td>
            <td><x-status :status="$expense->status"/></td>
            <td><a class="text-link" href="{{ route('expenses.show', $expense) }}">Ver despesa #{{ $expense->id }}</a></td>
            @else<td colspan="5">Registro #{{ $row['expense_id'] }} indisponível.</td>@endif
            </tr>
        @endforeach</tbody>
    </table></div>
    @else
    <div class="panel-body"><p>{{ $import->original_import_id ? 'Nenhuma despesa foi registrada neste envio. Consulte a importação original para ver as despesas já cadastradas.' : ($import->imported_rows ? 'Esta importação antiga registrou '.$import->imported_rows.' despesas, mas não guardou a ligação entre cada linha do CSV e o cadastro criado. Isso não é uma rejeição: as despesas continuam disponíveis em Ver despesas. Reenviar o mesmo arquivo recupera este resultado, sem cadastrar as despesas novamente.' : 'Nenhuma despesa foi registrada neste lote. Confira os motivos das linhas com erro abaixo.') }}</p></div>
    @endif
</section>

<section class="panel"><div class="panel-heading"><div><h2>{{ $import->failed_rows ? 'O que precisa de atenção' : 'Tudo certo com este arquivo' }}</h2><p>{{ $import->failed_rows ? 'Corrija as linhas abaixo e importe apenas os registros corrigidos.' : ($import->original_import_id ? 'As linhas válidas já haviam sido importadas e foram ignoradas neste envio.' : 'Todas as linhas válidas foram registradas. As conversões podem continuar em segundo plano.') }}</p></div><x-icon :name="$import->failed_rows ? 'alert' : 'check-circle'" :size="23"/></div>@if($import->failed_rows)<div class="table-scroll"><table class="data-table"><thead><tr><th>Linha do arquivo</th><th>Motivo da rejeição</th></tr></thead><tbody>@foreach($import->errors ?? [] as $error)<tr><td><span class="identifier">Linha {{ data_get($error, 'line') }}</span></td><td><ul class="error-reasons">@foreach((array) data_get($error, 'messages', []) as $message)<li>{{ is_array($message) ? implode(' ', $message) : $message }}</li>@endforeach</ul></td></tr>@endforeach</tbody></table></div>@else<div class="empty-state"><span class="success-circle"><x-icon name="check" :size="30"/></span><h3>{{ $import->status === 'completed' ? 'Importação concluída sem inconsistências' : 'Linhas confirmadas preservadas' }}</h3><p>Consulte as despesas para acompanhar o câmbio e os rateios.</p><a href="{{ route('expenses.index') }}" class="text-link">Acompanhar despesas<x-icon name="arrow-right" :size="16"/></a></div>@endif</section>

@endsection
