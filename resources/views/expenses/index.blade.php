@extends('layouts.app')
@section('title', 'Despesas')
@section('breadcrumb', 'Despesas')
@section('content')
<div class="page-heading"><div><div class="eyebrow">OPERAÇÃO FINANCEIRA</div><h1>Despesas<span class="orange-dot">.</span></h1><p>Todos os lançamentos, com a origem e o destino de cada valor.</p></div><div class="heading-actions"><a href="{{ route('expenses.create') }}" class="button button-primary"><x-icon name="plus" :size="18"/>Nova despesa</a></div></div>
<section class="panel">
    <form method="GET" class="filters"><div class="field search-field"><label for="search">Buscar despesa</label><div class="input-icon"><x-icon name="search" :size="18"/><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Descrição ou fornecedor"></div></div><div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">Todos os status</option><option value="converted" @selected(($filters['status'] ?? '') === 'converted')>Registrada</option></select></div><div class="field"><label for="currency">Moeda</label><select id="currency" name="currency"><option value="">Todas</option>@foreach(['BRL'] as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select></div><div class="field"><label for="from">De</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div><div class="field"><label for="to">Até</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div><button class="button button-dark" type="submit">Filtrar</button>@if(array_filter($filters))<a href="{{ route('expenses.index') }}" class="icon-button clear-filter" title="Limpar filtros" aria-label="Limpar filtros"><x-icon name="close" :size="18"/></a>@endif</form>
    <div class="table-title"><h2>Lançamentos <span class="count-badge">{{ $expenses->total() }}</span></h2><span class="muted small">Mais recentes primeiro</span></div>
    <x-expense-table :expenses="$expenses"/>
    <x-pagination :paginator="$expenses"/>
</section>
<div class="inline-help"><x-icon name="info" :size="16"/>O valor em reais aparece após a conversão. Abra uma despesa para consultar a taxa e o rateio.</div>
@endsection
