<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Clients sans achat — {{ $beat->name }}</title>
    <style>
        * { box-sizing: border-box; }
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
            border-bottom: 2px solid #d97706;
        }
        .header h1 {
            font-size: 20px;
            margin: 0 0 6px 0;
            color: #92400e;
        }
        .header .meta {
            font-size: 11px;
            color: #6b7280;
        }
        .header .meta span { margin: 0 8px; }
        .summary-row {
            margin-bottom: 20px;
        }
        .summary-row table {
            border: none;
        }
        .summary-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 10px 14px;
            text-align: center;
        }
        .summary-box .value {
            font-size: 20px;
            font-weight: bold;
            color: #92400e;
        }
        .summary-box .label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }
        .summary-box.ok .value { color: #065f46; }
        .summary-box.ok { background: #f0fdf4; border-color: #bbf7d0; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead tr {
            background-color: #92400e;
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
        tbody tr { border-bottom: 1px solid #e5e7eb; }
        tbody tr:nth-child(even) { background-color: #fffbeb; }
        tbody tr:last-child { border-bottom: none; }
        tbody td { padding: 8px 10px; vertical-align: middle; }
        .customer-name { font-weight: bold; }
        .phone { color: #6b7280; font-size: 11px; }
        .empty-row td {
            text-align: center;
            padding: 24px;
            color: #9ca3af;
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
        <h1>Clients sans achat — {{ $beat->name }}</h1>
        <div class="meta">
            <span>Date : <strong>{{ $date_label }}</strong></span>
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
        $leftOutCount = $left_out_customers->count();
        $buyersCount = $total_customers - $leftOutCount;
    @endphp

    <div class="summary-row">
        <table style="border:none;">
            <tr>
                <td style="width:33%; padding:0 8px 0 0; border:none;">
                    <div class="summary-box">
                        <div class="value">{{ $total_customers }}</div>
                        <div class="label">Clients du beat</div>
                    </div>
                </td>
                <td style="width:33%; padding:0 8px; border:none;">
                    <div class="summary-box ok">
                        <div class="value">{{ $buyersCount }}</div>
                        <div class="label">Ont acheté</div>
                    </div>
                </td>
                <td style="width:33%; padding:0 0 0 8px; border:none;">
                    <div class="summary-box">
                        <div class="value">{{ $leftOutCount }}</div>
                        <div class="label">Sans achat</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th style="width:95%">Client</th>
            </tr>
        </thead>
        <tbody>
            @forelse($left_out_customers as $index => $customer)
            <tr>
                <td style="color:#9ca3af; font-size:11px;">{{ $index + 1 }}</td>
                <td class="customer-name">
                    {{ $customer->name }}<br>
                    <span class="phone">  {{ $customer->address }}</span>

                </td>
            </tr>
            @empty
            <tr>
                <td colspan="3" class="empty-row">
                    Tous les clients du beat ont acheté ce jour. ✓
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Généré le {{ $generated_at }} · Confidentiel — usage interne uniquement
    </div>

</body>
</html>
