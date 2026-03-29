<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Top 50 Clients</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            line-height: 1.4;
        }

        /* ── Header ─────────────────────────────────────── */
        .page-header {
            display: table;
            width: 100%;
            margin-bottom: 24px;
            border-bottom: 2px solid #1a237e;
            padding-bottom: 12px;
        }

        .header-left {
            display: table-cell;
            vertical-align: middle;
            width: 50%;
        }

        .header-right {
            display: table-cell;
            vertical-align: middle;
            width: 50%;
            text-align: right;
        }

        .company-logo {
            width: 60px;
            height: 60px;
        }

        .company-name {
            font-size: 16px;
            font-weight: bold;
            color: #1a237e;
        }

        .company-details {
            font-size: 9px;
            color: #555;
            margin-top: 2px;
        }

        .report-title {
            font-size: 18px;
            font-weight: bold;
            color: #1a237e;
        }

        .report-subtitle {
            font-size: 10px;
            color: #555;
            margin-top: 4px;
        }

        /* ── Table ───────────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        thead tr {
            background-color: #1a237e;
            color: #fff;
        }

        thead th {
            padding: 8px 10px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        thead th.text-right {
            text-align: right;
        }

        tbody tr:nth-child(even) {
            background-color: #f5f7ff;
        }

        tbody tr:hover {
            background-color: #e8eaf6;
        }

        tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }

        tbody td.text-right {
            text-align: right;
        }

        /* ── Rank cell ───────────────────────────────────── */
        .rank {
            font-weight: bold;
            color: #888;
            text-align: center;
            width: 36px;
        }

        .rank-gold   { color: #f59e0b; font-size: 14px; }
        .rank-silver { color: #94a3b8; font-size: 14px; }
        .rank-bronze { color: #c2845a; font-size: 14px; }

        /* ── Customer cell ───────────────────────────────── */
        .customer-name {
            font-weight: bold;
        }

        .customer-address {
            font-size: 9px;
            color: #777;
            margin-top: 1px;
        }

        /* ── Number cells ────────────────────────────────── */
        .invoices-count {
            font-weight: bold;
            color: #1a237e;
            text-align: right;
        }

        .amount {
            text-align: right;
        }

        .profit {
            text-align: right;
            color: #166534;
            font-weight: bold;
        }

        /* ── Totals footer ───────────────────────────────── */
        tfoot tr {
            background-color: #1a237e;
            color: #fff;
            font-weight: bold;
        }

        tfoot td {
            padding: 8px 10px;
            border-top: 2px solid #1a237e;
        }

        tfoot td.text-right {
            text-align: right;
        }

        /* ── Page footer ─────────────────────────────────── */
        .page-footer {
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            font-size: 9px;
            color: #888;
            display: table;
            width: 100%;
        }

        .footer-left  { display: table-cell; text-align: left; }
        .footer-right { display: table-cell; text-align: right; }
    </style>
</head>
<body>

    {{-- ── Page header ──────────────────────────────────── --}}
    <div class="page-header">
        <div class="header-left">
            <img src="{{ public_path('logo.jpg') }}" class="company-logo" alt="Bayal Services">
            <div class="company-name" style="margin-top: 6px;">Bayal Services</div>
            <div class="company-details">
                Route de l'aéroport, DAKAR &nbsp;|&nbsp;
                Tél : 77 776 19 35 / 77 330 08 53
            </div>
        </div>
        <div class="header-right">
            <div class="report-title">Top 50 Clients</div>
            <div class="report-subtitle">Classement par {{ $sortLabel }}</div>
            <div class="report-subtitle" style="margin-top: 6px;">Généré le {{ $date }}</div>
        </div>
    </div>

    {{-- ── Table ────────────────────────────────────────── --}}
    <table>
        <thead>
            <tr>
                <th style="width: 36px; text-align: center;">#</th>
                <th>Client</th>
                <th>Téléphone</th>
                <th class="text-right">Factures</th>
                <th class="text-right">Total paiements</th>
                <th class="text-right">Profit réalisé</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topCustomers as $index => $customer)
                <tr>
                    {{-- Rank --}}
                    <td class="rank">
                        @if ($index === 0)
                            <span class="rank-gold">&#9733;</span>
                        @elseif ($index === 1)
                            <span class="rank-silver">&#9733;</span>
                        @elseif ($index === 2)
                            <span class="rank-bronze">&#9733;</span>
                        @else
                            {{ $index + 1 }}
                        @endif
                    </td>

                    {{-- Customer --}}
                    <td>
                        <div class="customer-name">{{ $customer->name }}</div>
                        @if ($customer->address)
                            <div class="customer-address">{{ $customer->address }}</div>
                        @endif
                    </td>

                    {{-- Phone --}}
                    <td>{{ $customer->phone_number ?: '—' }}</td>

                    {{-- Invoices count --}}
                    <td class="invoices-count">{{ number_format($customer->invoices_count, 0, ',', ' ') }}</td>

                    {{-- Total payments --}}
                    <td class="amount">{{ number_format($customer->total_payment, 0, ',', ' ') }} F</td>

                    {{-- Realized profit --}}
                    <td class="profit">{{ number_format($customer->total_realized_profit, 0, ',', ' ') }} F</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Totaux ({{ $topCustomers->count() }} clients)</td>
                <td class="text-right">{{ number_format($topCustomers->sum('invoices_count'), 0, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($topCustomers->sum('total_payment'), 0, ',', ' ') }} F</td>
                <td class="text-right">{{ number_format($topCustomers->sum('total_realized_profit'), 0, ',', ' ') }} F</td>
            </tr>
        </tfoot>
    </table>

    {{-- ── Page footer ──────────────────────────────────── --}}
    <div class="page-footer">
        <div class="footer-left">Bayal Distribution — Rapport confidentiel</div>
        <div class="footer-right">{{ $date }}</div>
    </div>

</body>
</html>
