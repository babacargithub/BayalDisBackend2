<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Factures impayées</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
        }
        .customer-info {
            margin-bottom: 4px;
        }
        .customer-address {
            font-size: 10px;
            color: #666;
        }
        .amount {
            text-align: right;
        }
        .remaining {
            color: red;
        }
        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>Liste des factures impayées</h2>
        <p>Généré le : {{ $date }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Client</th>
                <th>Total</th>
                <th>Avance</th>
                <th>Reste</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoices as $invoice)
                @php
                    $remaining = $invoice->total - $invoice->total_paid;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($invoice->created_at)->format('d/m/Y') }}</td>
                    <td>
                        <div class="customer-info">{{ $invoice->customer->name }}</div>
                        <div class="customer-address">{{ $invoice->customer->address }}</div>
                    </td>
                    <td class="amount">{{ number_format($invoice->total, 0, ',', ' ') }} F</td>
                    <td class="amount">{{ number_format($invoice->total_paid, 0, ',', ' ') }} F</td>
                    <td class="amount remaining">{{ number_format($remaining, 0, ',', ' ') }} F</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th class="amount">{{ number_format($invoices->sum('total'), 0, ',', ' ') }} F</th>
                <th class="amount">{{ number_format($invoices->sum('total_paid'), 0, ',', ' ') }} F</th>
                <th class="amount remaining">{{ number_format($invoices->sum('total') - $invoices->sum('total_paid'), 0, ',', ' ') }} F</th>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Bayal Distribution - Liste des factures impayées au {{ $date }}</p>
    </div>
</body>
</html> 