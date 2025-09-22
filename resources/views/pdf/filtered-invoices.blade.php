<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Factures</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .week-title {
            text-align: center;
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 16px;
            font-weight: bold;
        }
        .filter-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
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
        .summary {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .summary h3 {
            margin-top: 0;
            color: #333;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>Liste des factures</h2>
        <p>Généré le : {{ $date }}</p>
    </div>

    @if($weekRangeTitle)
        <div class="week-title">
            {{ $weekRangeTitle }}
        </div>
    @endif

    <div class="filter-info">
        <strong>Filtres appliqués :</strong> {{ $filterDescription }}
    </div>

    @if($invoices->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Téléphone</th>
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
                        <td>{{ $invoice->customer->phone_number }}</td>
                        <td class="amount">{{ number_format($invoice->total, 0, ',', ' ') }} F</td>
                        <td class="amount">{{ number_format($invoice->total_paid, 0, ',', ' ') }} F</td>
                        <td class="amount {{ $remaining > 0 ? 'remaining' : '' }}">{{ number_format($remaining, 0, ',', ' ') }} F</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Total ({{ $invoices->count() }} factures)</th>
                    <th class="amount">{{ number_format($totalAmount, 0, ',', ' ') }} F</th>
                    <th class="amount">{{ number_format($totalPaid, 0, ',', ' ') }} F</th>
                    <th class="amount {{ $totalRemaining > 0 ? 'remaining' : '' }}">{{ number_format($totalRemaining, 0, ',', ' ') }} F</th>
                </tr>
            </tfoot>
        </table>

        <div class="summary">
            <h3>Résumé</h3>
            <div class="summary-row">
                <span>Nombre de factures :</span>
                <span><strong>{{ $invoices->count() }}</strong></span>
            </div>
            <div class="summary-row">
                <span>Montant total :</span>
                <span><strong>{{ number_format($totalAmount, 0, ',', ' ') }} F</strong></span>
            </div>
            <div class="summary-row">
                <span>Total payé :</span>
                <span><strong>{{ number_format($totalPaid, 0, ',', ' ') }} F</strong></span>
            </div>
            <div class="summary-row">
                <span>Reste à payer :</span>
                <span class="{{ $totalRemaining > 0 ? 'remaining' : '' }}"><strong>{{ number_format($totalRemaining, 0, ',', ' ') }} F</strong></span>
            </div>
        </div>
    @else
        <div style="text-align: center; padding: 50px; color: #666;">
            <h3>Aucune facture trouvée</h3>
            <p>Les filtres appliqués n'ont retourné aucun résultat.</p>
        </div>
    @endif

    <div class="footer">
        <p>Bayal Distribution - Rapport généré le {{ $date }}</p>
    </div>
</body>
</html>