<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Stock Chargement - {{ $carLoad->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .info {
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
        .total-row {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Chargement - {{ $carLoad->name }}</h1>
    </div>

    <div class="info">
        <p><strong>Responsable:</strong> {{ $carLoad->commercial->name }}</p>
        <p><strong>Date de chargement:</strong> {{ $carLoad->load_date ? $carLoad->load_date->format('d/m/Y') :
        'Non précisé' }}</p>
        <p><strong>Date de retour prévue:</strong> {{ $carLoad->return_date ? $carLoad->return_date->format('d/m/Y')
        : 'Non précisé' }}</p>
        @if($carLoad->comment)
        <p><strong>Commentaire:</strong> {{ $carLoad->comment }}</p>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th class="text-right">Quantité</th>
                <th class="text-right">Prix unitaire</th>
                <th class="text-right">Total</th>
                <th>Commentaire</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQuantity = 0;
                $totalAmount = 0;
            @endphp
            @foreach($items as $item)
                @php
                    $totalQuantity += $item->quantity_loaded;
                    $totalAmount += $item->quantity_loaded * $item->product->price;
                @endphp
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td class="text-right">{{ $item->quantity_loaded }}</td>
                    <td class="text-right">{{ number_format($item->product->price, 0, ',', ' ') }} F</td>
                    <td class="text-right">{{ number_format($item->quantity_loaded * $item->product->price, 0, ',', ' ') }} F</td>
                    <td>{{ $item->comment }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td><strong>TOTAL</strong></td>
                <td class="text-right">{{ $totalQuantity }}</td>
                <td></td>
                <td class="text-right">{{ number_format($totalAmount, 0, ',', ' ') }} F</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>Document généré le {{ now()->format('d/m/Y H:i') }}</p>
    </div>
</body>
</html> 