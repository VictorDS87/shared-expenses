<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#081122"><title>@yield('title', 'Visão geral') · Grid Finance</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
<a class="skip-link" href="#main-content">Pular para o conteúdo</a>
<button class="sidebar-overlay" type="button" aria-label="Fechar menu" data-sidebar-close></button>
<aside class="sidebar" id="sidebar" aria-label="Menu principal">
    <a class="brand" href="{{ route('dashboard') }}"><img src="{{ asset('brand/gridmidia-white.svg') }}" alt="Grupo Gridmidia" width="162"><span>FINANCE</span></a>
    <div class="workspace-label"><span class="workspace-symbol"><x-icon name="building" :size="17"/></span><span>Gestão corporativa<small>Grupo Gridmidia</small></span><span class="workspace-dot"></span></div>
    <div class="nav-label">WORKSPACE</div>
    <nav class="main-nav">
    @foreach([['units.*', 'building', 'Unidades']] as [$pattern, $icon, $label])
        @php($linkRoute = $pattern === 'dashboard' ? $pattern : str_replace('.*', '.index', $pattern))
        <a href="{{ route($linkRoute) }}" @class(['nav-link', 'active' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif><x-icon :name="$icon" :size="20"/><span>{{ $label }}</span>@if(request()->routeIs($pattern))<span class="nav-active-dot"></span>@endif</a>
    @endforeach
    </nav>
    <div class="sidebar-bottom">
        <div class="sidebar-note"><span class="sidebar-note-icon"><x-icon name="shield" :size="20"/></span><strong>Clareza em cada centavo.</strong><p>Organize os centros de custo das empresas do grupo.</p></div>
        <div class="sidebar-user"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="user-meta"><strong>{{ auth()->user()->name }}</strong><small>Equipe financeira</small></span><form action="{{ route('logout') }}" method="POST">@csrf<button class="logout-button" title="Sair da conta" aria-label="Sair da conta"><x-icon name="logout" :size="18"/></button></form></div>
    </div>
</aside>
<div class="app-shell">
    <header class="topbar"><div class="topbar-start"><button class="icon-button mobile-menu" type="button" aria-controls="sidebar" aria-expanded="false" data-sidebar-toggle aria-label="Abrir menu"><x-icon name="menu"/></button><span class="breadcrumb">Workspace<x-icon name="chevron" :size="13"/><strong>@yield('breadcrumb', 'Visão geral')</strong></span></div><div class="topbar-end">@if(app()->environment(['local', 'testing']))<span class="environment-tag"><span></span>Ambiente de demonstração</span>@endif<span class="topbar-divider"></span><span class="topbar-date">{{ now()->locale('pt_BR')->isoFormat('D [de] MMM, YYYY') }}</span></div></header>
    <main id="main-content" class="main-content">
        @foreach(['success' => 'check-circle', 'info' => 'info'] as $type => $icon)
        @if(session($type))<div class="notice notice-{{ $type }}" role="status"><x-icon :name="$icon"/><span>{{ session($type) }}</span><button type="button" class="icon-button" data-dismiss aria-label="Fechar aviso"><x-icon name="close" :size="17"/></button></div>@endif
        @endforeach
        @if($errors->any())<div class="notice notice-error" role="alert"><x-icon name="alert"/><div><strong>Confira os dados antes de continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
        <footer class="page-footer"><span>Grid Finance <span class="footer-dot">·</span> Grupo Gridmidia</span><span>Gestão que conecta o grupo.</span></footer>
    </main>
</div>
@stack('scripts')
</body></html>
