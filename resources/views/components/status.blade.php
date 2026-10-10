@props(['status'])
@php($labels = ['converted' => 'Registrada'])
<span {{ $attributes->merge(['class' => 'status status-'.$status]) }}><span class="status-dot"></span>{{ $labels[$status] ?? $status }}</span>
