<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Bayal Distribution') — Bayal Distribution</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700" rel="stylesheet" />
    @vite('resources/css/app.css')
    @stack('head')
    <style>
        /* Sidebar rail / mobile states — driven by JS class toggles */
        #sidebar               { width: 256px; transition: width 200ms ease, transform 200ms ease; }
        #sidebar.rail          { width: 64px; }
        #sidebar.mobile-hidden { transform: translateX(-100%); }

        /* Hide text labels and chevrons in rail mode */
        #sidebar.rail .nav-label,
        #sidebar.rail .nav-chevron { display: none !important; }

        /* Centre icons in rail mode */
        #sidebar.rail .nav-group-toggle,
        #sidebar.rail .nav-item { justify-content: center; padding-left: 0; padding-right: 0; }

        /* Main content offset */
        #main-content                        { margin-left: 256px; transition: margin-left 200ms ease; }
        #sidebar.rail ~ * #main-content,
        .rail-active #main-content           { margin-left: 64px; }

        /* Mobile: no margin offset */
        @media (max-width: 959px) {
            #main-content { margin-left: 0 !important; }
        }

        /* Smooth chevron rotation */
        .nav-chevron { transition: transform 200ms ease; }
        .nav-chevron.open { transform: rotate(180deg); }
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">

@php
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    $currentRoute = Route::currentRouteName() ?? '';

    $menuGroups = [
        [
            'name' => 'Données',
            'icon' => 'mdi-view-dashboard',
            'items' => [
                ['name' => 'Tableau de bord',  'route' => 'dashboard',          'icon' => 'mdi-view-dashboard'],
                ['name' => 'Rapports',          'route' => 'admin.rapport',      'icon' => 'mdi-chart-box'],
                ['name' => 'Statistiques',      'route' => 'admin.statistiques', 'icon' => 'mdi-chart-line'],
                ['name' => 'Zones & Lignes',    'route' => 'admin.geo-stats',    'icon' => 'mdi-map-marker-path'],
            ],
        ],
        [
            'name' => 'Ventes',
            'icon' => 'mdi-cash-register',
            'items' => [
                ['name' => 'Factures du jour',        'route' => 'ventes.index',         'icon' => 'mdi-cash-register'],
                ['name' => 'Tournées de la semaine',  'route' => 'ventes.weekly-rounds', 'icon' => 'mdi-map-marker-path'],
                ['name' => 'Dettes clients',           'route' => 'sales-invoices.index', 'icon' => 'mdi-file-document-outline'],
                ['name' => 'Commandes',                'route' => 'orders.index',         'icon' => 'mdi-package'],
                ['name' => 'Lots de livraison',        'route' => 'delivery-batches.index', 'icon' => 'mdi-truck-delivery'],
            ],
        ],
        [
            'name' => 'CRM',
            'icon' => 'mdi-account-group',
            'items' => [
                ['name' => 'Clients',             'route' => 'clients.index',          'icon' => 'mdi-account-group'],
                ['name' => 'Beats',               'route' => 'beats.index',            'icon' => 'mdi-map-marker-check'],
                ['name' => 'Activités',           'route' => 'clients.activity-map',   'icon' => 'mdi-map-marker-star'],
                ['name' => 'Top Clients',         'route' => 'clients.top-customers',  'icon' => 'mdi-trophy'],
                ['name' => 'Analyse de zones',    'route' => 'clients.area-analysis',  'icon' => 'mdi-map-search'],
                ['name' => 'Secteurs',            'route' => 'sectors.index',          'icon' => 'mdi-map-marker-multiple'],
                ['name' => 'Catégories client',   'route' => 'customer-categories.index', 'icon' => 'mdi-folder-account'],
                ['name' => 'Étiquettes client',   'route' => 'customer-tags.index',    'icon' => 'mdi-tag-multiple'],
                ['name' => 'Zones',               'route' => 'zones.index',            'icon' => 'mdi-map-marker-radius'],
            ],
        ],
        [
            'name' => 'Commerciaux',
            'icon' => 'mdi-account-tie',
            'items' => [
                ['name' => 'Commerciaux', 'route' => 'commerciaux.index', 'icon' => 'mdi-account-tie'],
                ['name' => 'Commissions', 'route' => 'commissions.index', 'icon' => 'mdi-cash-check'],
                ['name' => 'Équipes',     'route' => 'teams.index',       'icon' => 'mdi-account-group'],
            ],
        ],
        [
            'name' => 'Caisses',
            'icon' => 'mdi-cash-register',
            'items' => [
                ['name' => 'Caisses',   'route' => 'caisses.index',   'icon' => 'mdi-cash-register'],
                ['name' => 'Comptes',   'route' => 'accounts.index',  'icon' => 'mdi-bank-outline'],
                ['name' => 'Dépenses',  'route' => 'depenses.index',  'icon' => 'mdi-cash-minus'],
            ],
        ],
        [
            'name' => 'Stock',
            'icon' => 'mdi-warehouse',
            'items' => [
                ['name' => 'Chargements Véhicule', 'route' => 'car-loads.index',          'icon' => 'mdi-car'],
                ['name' => 'Produits',              'route' => 'produits.index',           'icon' => 'mdi-package-variant-closed'],
                ['name' => 'Catégories',            'route' => 'product-categories.index', 'icon' => 'mdi-tag-multiple'],
                ['name' => 'Factures Achats',       'route' => 'purchase-invoices.index',  'icon' => 'mdi-file-document-outline'],
                ['name' => 'Fournisseurs',          'route' => 'suppliers.index',          'icon' => 'mdi-handshake'],
                ['name' => 'Véhicules',             'route' => 'vehicles.index',           'icon' => 'mdi-truck'],
            ],
        ],
        [
            'name' => 'Admin',
            'icon' => 'mdi-shield-account',
            'items' => [
                ['name' => 'RH',                    'route' => 'admin.rh',                  'icon' => 'mdi-account-hard-hat'],
                ['name' => 'Utilisateurs',          'route' => 'users.index',               'icon' => 'mdi-account-multiple'],
                ['name' => 'Investissements',       'route' => 'investments.index',         'icon' => 'mdi-cash-multiple'],
                ['name' => "Coûts d'exploitation",  'route' => 'monthly-fixed-costs.index', 'icon' => 'mdi-office-building-cog'],
                ['name' => 'Politique de prix',     'route' => 'pricing-policies.index',    'icon' => 'mdi-tag-text'],
            ],
        ],
    ];

    // Find which group owns the current route so it opens by default
    $activeGroupName = null;
    foreach ($menuGroups as $group) {
        foreach ($group['items'] as $item) {
            if ($currentRoute === $item['route']) {
                $activeGroupName = $group['name'];
                break 2;
            }
        }
    }
@endphp

<div id="app-shell" class="flex min-h-screen">

    {{-- ═══════════════════════════════════════════════════════════
         SIDEBAR
         ═══════════════════════════════════════════════════════════ --}}
    <aside
        id="sidebar"
        class="fixed inset-y-0 left-0 z-40 flex flex-col overflow-hidden select-none"
        style="background: darkblue"
    >
        {{-- Logo --}}
        <div class="flex items-center px-4 py-3 min-h-[64px] overflow-hidden">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <img src="/logo.jpg" alt="Logo" class="h-10 flex-shrink-0">
            </a>
        </div>

        {{-- Divider --}}
        <div class="border-t border-blue-800"></div>

        {{-- Rail toggle --}}
        <div class="flex items-center justify-end px-2 py-1">
            <button
                id="rail-toggle"
                class="p-1.5 rounded text-white hover:bg-blue-800 transition-colors"
            >
                <i id="rail-icon" class="mdi mdi-chevron-left text-xl"></i>
            </button>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto overflow-x-hidden px-2 py-1 space-y-0.5 scrollbar-thin">
            @foreach($menuGroups as $group)
            @php
                $isActiveGroup = $activeGroupName === $group['name'];
            @endphp

            <div class="nav-group">

                {{-- Group header --}}
                <button
                    class="nav-group-toggle w-full flex items-center gap-3 px-3 py-2 rounded text-sm text-white
                           hover:bg-blue-800 transition-colors {{ $isActiveGroup ? 'bg-blue-800' : '' }}"
                    data-group-name="{{ $group['name'] }}"
                    data-open="{{ $isActiveGroup ? 'true' : 'false' }}"
                >
                    <i class="mdi {{ $group['icon'] }} text-lg w-5 flex-shrink-0 text-center"></i>
                    <span class="nav-label flex-1 text-left whitespace-nowrap overflow-hidden text-ellipsis">{{ $group['name'] }}</span>
                    <i class="mdi mdi-chevron-down nav-chevron text-base {{ $isActiveGroup ? 'open' : '' }}"></i>
                </button>

                {{-- Group items --}}
                <div class="nav-group-items {{ $isActiveGroup ? '' : 'hidden' }} pl-1 mt-0.5 space-y-0.5">
                    @foreach($group['items'] as $item)
                    @php
                        $isActive = $currentRoute === $item['route'];
                    @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        class="nav-item flex items-center gap-3 px-3 py-1.5 rounded text-sm transition-colors
                               {{ $isActive
                                   ? 'bg-white text-blue-900 font-semibold'
                                   : 'text-blue-100 hover:bg-blue-800 hover:text-white' }}"
                    >
                        <i class="mdi {{ $item['icon'] }} text-base w-5 flex-shrink-0 text-center"></i>
                        <span class="nav-label whitespace-nowrap overflow-hidden text-ellipsis">{{ $item['name'] }}</span>
                    </a>
                    @endforeach
                </div>

            </div>
            @endforeach
        </nav>
    </aside>

    {{-- Mobile backdrop --}}
    <div
        id="sidebar-backdrop"
        class="fixed inset-0 z-30 bg-black/50 hidden"
        onclick="closeSidebar()"
    ></div>

    {{-- ═══════════════════════════════════════════════════════════
         MAIN
         ═══════════════════════════════════════════════════════════ --}}
    <div id="main-content" class="flex-1 flex flex-col min-w-0 min-h-screen">

        {{-- App Bar --}}
        <header class="sticky top-0 z-20 bg-white shadow-sm h-14 flex items-center px-4 gap-3 flex-shrink-0">
            {{-- Mobile hamburger --}}
            <button
                id="mobile-menu-btn"
                class="hidden p-2 rounded text-gray-600 hover:bg-gray-100 transition-colors"
                onclick="openSidebar()"
            >
                <i class="mdi mdi-menu text-xl"></i>
            </button>

            {{-- Page title --}}
            <div class="flex-1 text-gray-800 font-medium text-sm">
                @yield('header')
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 p-4 md:p-6 overflow-auto">
            @yield('content')
        </main>

    </div>
</div>

<script>
(function () {
    var sidebar     = document.getElementById('sidebar');
    var mainContent = document.getElementById('main-content');
    var backdrop    = document.getElementById('sidebar-backdrop');
    var railToggle  = document.getElementById('rail-toggle');
    var railIcon    = document.getElementById('rail-icon');
    var mobileBtn   = document.getElementById('mobile-menu-btn');

    var isRail   = localStorage.getItem('sidebar-rail') === 'true';
    var isMobile = false;

    /* ── Rail mode ────────────────────────────────── */
    function applyRail() {
        if (isRail) {
            sidebar.classList.add('rail');
            mainContent.style.marginLeft = '64px';
            railIcon.classList.remove('mdi-chevron-left');
            railIcon.classList.add('mdi-chevron-right');
        } else {
            sidebar.classList.remove('rail');
            mainContent.style.marginLeft = '256px';
            railIcon.classList.remove('mdi-chevron-right');
            railIcon.classList.add('mdi-chevron-left');
        }
    }

    railToggle.addEventListener('click', function () {
        if (isMobile) return;
        isRail = !isRail;
        localStorage.setItem('sidebar-rail', String(isRail));
        applyRail();
    });

    /* ── Mobile drawer ────────────────────────────── */
    window.openSidebar = function () {
        sidebar.classList.remove('mobile-hidden');
        backdrop.classList.remove('hidden');
    };

    window.closeSidebar = function () {
        sidebar.classList.add('mobile-hidden');
        backdrop.classList.add('hidden');
    };

    /* ── Responsive check ─────────────────────────── */
    function checkBreakpoint() {
        var wasMobile = isMobile;
        isMobile = window.innerWidth < 960;

        if (isMobile) {
            sidebar.classList.add('mobile-hidden');
            mainContent.style.marginLeft = '0';
            mobileBtn.classList.remove('hidden');
        } else {
            sidebar.classList.remove('mobile-hidden');
            backdrop.classList.add('hidden');
            mobileBtn.classList.add('hidden');
            applyRail();
        }
    }

    window.addEventListener('resize', checkBreakpoint);
    checkBreakpoint();
    if (!isMobile) applyRail();

    /* ── Accordion groups ─────────────────────────── */
    document.querySelectorAll('.nav-group-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group   = this.closest('.nav-group');
            var items   = group.querySelector('.nav-group-items');
            var chevron = this.querySelector('.nav-chevron');
            var isOpen  = !items.classList.contains('hidden');

            if (isOpen) {
                items.classList.add('hidden');
                chevron.classList.remove('open');
                this.dataset.open = 'false';
            } else {
                items.classList.remove('hidden');
                chevron.classList.add('open');
                this.dataset.open = 'true';
            }
        });
    });
})();
</script>

</body>
</html>
