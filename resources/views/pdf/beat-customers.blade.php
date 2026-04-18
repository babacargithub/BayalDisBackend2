<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Beat {{ $beat->name }}</title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            margin: 20px;
            color: #1a1a1a;
        }
        .header {
            text-align: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #2563eb;
        }
        .header h1 {
            font-size: 20px;
            margin: 0 0 6px 0;
            color: #1e40af;
        }
        .header .meta {
            font-size: 11px;
            color: #6b7280;
        }
        .header .meta span {
            margin: 0 8px;
        }
        .summary {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-box {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
            text-align: center;
        }
        .summary-box .value {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
        }
        .summary-box .label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead tr {
            background-color: #1e40af;
            color: #ffffff;
        }
        thead th {
            padding: 9px 10px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        thead th.right {
            text-align: right;
        }
        tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        tbody tr:last-child {
            border-bottom: none;
        }
        tbody td {
            padding: 8px 10px;
            vertical-align: top;
        }
        .customer-name {
            font-weight: bold;
        }
        .customer-address {
            font-size: 11px;
            color: #6b7280;
            margin-top: 2px;
        }
        .amount {
            text-align: right;
            font-weight: bold;
            white-space: nowrap;
        }
        .amount.danger {
            color: #dc2626;
        }
        .amount.zero {
            color: #6b7280;
            font-weight: normal;
        }
        .count {
            text-align: center;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .badge-ok {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        tfoot tr {
            background-color: #f1f5f9;
            font-weight: bold;
        }
        tfoot td {
            padding: 9px 10px;
            border-top: 2px solid #cbd5e1;
        }
        .footer {
            margin-top: 20px;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Beat — {{ $beat->name }}</h1>
        <div class="meta">
            <span>Jour : <strong>{{ $beat->day_of_week?->label() }}</strong></span>
            @if($beat->commercial)
            <span>·</span>
            <span>Commercial : <strong>{{ $beat->commercial->name }}</strong></span>
            @endif
            @if($beat->sector)
            <span>·</span>
            <span>Secteur : <strong>{{ $beat->sector->name }}</strong></span>
            @endif
        </div>
    </div>

    @php
        $totalDebt = $customers->sum('total_debt');
        $totalUnpaidInvoices = $customers->sum('unpaid_invoices_count');
        $customersWithDebt = $customers->where('total_debt', '>', 0)->count();
    @endphp

    <table style="margin-bottom:16px; border:none;">
        <tr>
            <td style="width:25%; padding:0 8px 0 0; border:none;">
                <div class="summary-box">
                    <div class="value">{{ $customers->count() }}</div>
                    <div class="label">Clients</div>
                </div>
            </td>
            <td style="width:25%; padding:0 8px; border:none;">
                <div class="summary-box">
                    <div class="value" style="color:#dc2626;">{{ $customersWithDebt }}</div>
                    <div class="label">Avec dette</div>
                </div>
            </td>
            <td style="width:25%; padding:0 8px; border:none;">
                <div class="summary-box">
                    <div class="value" style="color:#dc2626;">{{ number_format($totalDebt) }}</div>
                    <div class="label">Total dettes (XOF)</div>
                </div>
            </td>
            <td style="width:25%; padding:0 0 0 8px; border:none;">
                <div class="summary-box">
                    <div class="value">{{ $totalUnpaidInvoices }}</div>
                    <div class="label">Factures impayées</div>
                </div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:38%">Client</th>
                <th style="width:30%">Adresse</th>
                <th class="right" style="width:18%">Dette totale</th>
                <th class="right" style="width:10%">Factures</th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $index => $customer)
            <tr>
                <td style="color:#9ca3af; font-size:11px;">{{ $index + 1 }}</td>
                <td>
                    <div class="customer-name">{{ $customer['name'] }}</div>
                </td>
                <td>
                    <div class="customer-address">{{ $customer['address'] ?: '—' }}</div>
                </td>
                <td class="amount {{ $customer['total_debt'] > 0 ? 'danger' : 'zero' }}">
                    {{ $customer['total_debt'] > 0 ? number_format($customer['total_debt']) . ' XOF' : '—' }}
                </td>
                <td class="count">
                    @if($customer['unpaid_invoices_count'] > 0)
                        <span class="badge badge-danger">{{ $customer['unpaid_invoices_count'] }}</span>
                    @else
                        <span class="badge badge-ok">0</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align:center; padding:24px; color:#9ca3af;">
                    Aucun client dans ce beat.
                </td>
            </tr>
            @endforelse
        </tbody>
        @if($customers->count() > 0)
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right;">Total</td>
                <td class="amount danger" style="text-align:right;">
                    {{ number_format($totalDebt) }} XOF
                </td>
                <td class="count">{{ $totalUnpaidInvoices }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <div class="footer">
        Généré le {{ $generated_at }} · Confidentiel — usage interne uniquement
    </div>

</body>
</html>
