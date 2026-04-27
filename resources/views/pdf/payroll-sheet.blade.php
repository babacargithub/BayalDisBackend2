<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche de paie - {{ $commercial->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            background: #fff;
        }

        .page {
            padding: 30px 36px;
        }

        /* Header */
        .header {
            width: 100%;
            margin-bottom: 24px;
            border-bottom: 3px solid #1a56a0;
            padding-bottom: 16px;
        }
        .header-left {
            float: left;
            width: 48%;
        }
        .header-right {
            float: right;
            width: 48%;
            text-align: right;
        }
        .clearfix::after { content: ''; display: table; clear: both; }

        .company-logo {
            width: 64px;
            height: 64px;
            margin-bottom: 6px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #1a56a0;
        }
        .company-sub {
            font-size: 11px;
            color: #555;
            margin-top: 2px;
        }

        .doc-title {
            font-size: 22px;
            font-weight: bold;
            color: #1a56a0;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .doc-meta {
            font-size: 11px;
            color: #555;
            line-height: 1.7;
        }

        /* Employee card */
        .employee-card {
            background: #f0f5fc;
            border-left: 4px solid #1a56a0;
            padding: 12px 16px;
            margin-bottom: 22px;
            border-radius: 2px;
        }
        .employee-name {
            font-size: 16px;
            font-weight: bold;
            color: #1a56a0;
            margin-bottom: 4px;
        }
        .employee-meta {
            font-size: 11px;
            color: #444;
            line-height: 1.7;
        }

        /* Section labels */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #fff;
            background: #1a56a0;
            padding: 5px 10px;
            margin-bottom: 0;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
            font-size: 12px;
        }
        table thead tr {
            background: #dce8f8;
        }
        table thead th {
            padding: 7px 10px;
            text-align: left;
            font-weight: bold;
            font-size: 11px;
            color: #1a56a0;
            border-bottom: 1px solid #b3ccee;
        }
        table thead th.text-right { text-align: right; }
        table tbody tr:nth-child(even) { background: #f7faff; }
        table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e8e8e8;
            color: #222;
        }
        table tbody td.text-right { text-align: right; }
        table tbody td.label { color: #555; padding-left: 24px; }

        .subtotal-row td {
            background: #eaf1fb !important;
            font-weight: bold;
            color: #1a56a0;
            border-top: 1px solid #b3ccee;
        }

        .penalty-date { color: #888; font-size: 11px; }
        .penalty-reason { color: #333; }
        .penalty-amount { color: #c0392b; font-weight: bold; text-align: right; }

        .total-penalties-row td {
            background: #fdf0ee !important;
            font-weight: bold;
            color: #c0392b;
            border-top: 2px solid #e8a89e;
        }

        /* Net to pay */
        .net-box {
            background: #1a56a0;
            color: #fff;
            padding: 14px 20px;
            margin-top: 6px;
            margin-bottom: 24px;
            border-radius: 3px;
            overflow: hidden;
        }
        .net-box-label {
            float: left;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 0.5px;
            line-height: 26px;
        }
        .net-box-amount {
            float: right;
            font-size: 22px;
            font-weight: bold;
        }

        /* Signatures */
        .signatures {
            margin-top: 32px;
            width: 100%;
        }
        .sig-block {
            float: left;
            width: 45%;
            text-align: center;
        }
        .sig-block-right {
            float: right;
            width: 45%;
            text-align: center;
        }
        .sig-label {
            font-size: 11px;
            font-weight: bold;
            color: #444;
            margin-bottom: 40px;
        }
        .sig-line {
            border-top: 1px solid #aaa;
            padding-top: 4px;
            font-size: 10px;
            color: #888;
        }

        /* Footer */
        .footer {
            margin-top: 24px;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            text-align: center;
            font-size: 10px;
            color: #aaa;
        }
    </style>
</head>
<body>
<div class="page">

    {{-- Header --}}
    <div class="header clearfix">
        <div class="header-left">
            @if($logoDataUri)
            <img src="{{ $logoDataUri }}" class="company-logo" alt="Bayal Services">
            @endif
            <div class="company-name">Bayal Services</div>
            <div class="company-sub">Distribution &amp; Commerce - Dakar, Sénégal</div>
        </div>
        <div class="header-right">
            <div class="doc-title">BULLETIN DE PAIE</div>
            <div class="doc-meta">
                Période : {{ \Carbon\Carbon::parse($startDate)->locale('fr')->isoFormat('D MMMM YYYY') }}
                au {{ \Carbon\Carbon::parse($endDate)->locale('fr')->isoFormat('D MMMM YYYY') }}<br>
                Emis le : {{ $generatedAt->locale('fr')->isoFormat('D MMMM YYYY') }}
            </div>
        </div>
    </div>

    {{-- Employee card --}}
    <div class="employee-card">
        <div class="employee-name">{{ $commercial->name }}</div>
        <div class="employee-meta">
            Equipe : {{ $commercial->team?->name ?? 'N/A' }}
            &nbsp;|&nbsp;
            Tel : {{ $commercial->phone_number ?? 'N/A' }}
            &nbsp;|&nbsp;
            Poste : Commercial terrain
        </div>
    </div>

    {{-- Earnings --}}
    <div class="section-title">Gains</div>
    <table>
        <thead>
            <tr>
                <th>Rubrique</th>
                <th class="text-right">Montant (F CFA)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Salaire de base</td>
                <td class="text-right">{{ number_format($baseSalary, 0, ',', ' ') }}</td>
            </tr>
            <tr>
                <td>Commissions sur ventes</td>
                <td class="text-right">{{ number_format($totalBaseCommission, 0, ',', ' ') }}</td>
            </tr>
            @if($totalBasketBonus > 0)
            <tr>
                <td class="label">Bonus panier produits</td>
                <td class="text-right">{{ number_format($totalBasketBonus, 0, ',', ' ') }}</td>
            </tr>
            @endif
            @if($totalObjectiveBonus > 0)
            <tr>
                <td class="label">Bonus objectif</td>
                <td class="text-right">{{ number_format($totalObjectiveBonus, 0, ',', ' ') }}</td>
            </tr>
            @endif
            @if($totalNewConfirmedCustomersBonus > 0)
            <tr>
                <td class="label">Bonus nouveaux clients confirmés</td>
                <td class="text-right">{{ number_format($totalNewConfirmedCustomersBonus, 0, ',', ' ') }}</td>
            </tr>
            @endif
            @if($totalNewProspectCustomersBonus > 0)
            <tr>
                <td class="label">Bonus prospects convertis</td>
                <td class="text-right">{{ number_format($totalNewProspectCustomersBonus, 0, ',', ' ') }}</td>
            </tr>
            @endif
            <tr class="subtotal-row">
                <td>Total commissions</td>
                <td class="text-right">{{ number_format($totalGrossCommission, 0, ',', ' ') }}</td>
            </tr>
            <tr class="subtotal-row">
                <td>TOTAL BRUT</td>
                <td class="text-right">{{ number_format($totalGrossEarnings, 0, ',', ' ') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Penalties --}}
    <div class="section-title">Déductions - Pénalités</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Motif</th>
                <th class="text-right">Montant (F CFA)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($penalties as $penalty)
            <tr>
                <td class="penalty-date">{{ \Carbon\Carbon::parse($penalty->work_day)->locale('fr')->isoFormat('D MMM YYYY') }}</td>
                <td class="penalty-reason">{{ $penalty->reason }}</td>
                <td class="penalty-amount">- {{ number_format($penalty->amount, 0, ',', ' ') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="3" style="text-align:center; color:#888; font-style:italic;">
                    Aucune pénalité sur cette période
                </td>
            </tr>
            @endforelse
            <tr class="total-penalties-row">
                <td colspan="2">TOTAL DEDUCTIONS</td>
                <td class="text-right" style="color:#c0392b;">- {{ number_format($totalPenalties, 0, ',', ' ') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Net to pay --}}
    <div class="net-box clearfix">
        <div class="net-box-label">NET A PAYER</div>
        <div class="net-box-amount">{{ number_format($netToPay, 0, ',', ' ') }} F CFA</div>
    </div>

    {{-- Signatures --}}
    <div class="signatures clearfix">
        <div class="sig-block">
            <div class="sig-label">Signature de l'employeur</div>
            <div class="sig-line">Bayal Services</div>
        </div>
        <div class="sig-block-right">
            <div class="sig-label">Signature de l'employe</div>
            <div class="sig-line">{{ $commercial->name }}</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Document généré automatiquement par le système Bayal - Confidentiel
    </div>

</div>
</body>
</html>