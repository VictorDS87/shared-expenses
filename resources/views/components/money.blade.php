@props(['value', 'currency' => 'BRL'])
@php
    [$whole, $cents] = explode('.', \App\Domain\Money\DecimalMoney::format((int) ($value ?? 0)));
    $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$cents;
@endphp
<span {{ $attributes->merge(['class' => 'money']) }}>{{ ['BRL' => 'R$', 'USD' => 'US$'][$currency] ?? $currency }} {{ $formatted }}</span>
