@props(['status'])
@php($labels = ['converted' => 'Convertida', 'pending' => 'Em processamento', 'failed' => 'Falha na conversão'])
<span {{ $attributes->merge(['class' => 'status status-'.$status]) }}><span class="status-dot"></span>{{ $labels[$status] ?? $status }}</span>
