<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Inventaire - {{ $carLoad->name }}</title>
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
        .result {
            font-weight: bold;
        }
        .negative {
            color: red;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 0.8em;
        }
        .total-row {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .price {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Inventaire - {{ $carLoad->name }}</h1>
        <p>{{ $inventory->name }}</p>
    </div>

    <div class="info">
        <p><strong>Commercial:</strong> {{ $carLoad->commercial->name }}</p>
        <p><strong>Date d'inventaire:</strong> {{ $date }}</p>
        <p><strong>Créé par:</strong> {{ $inventory->user->name }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th class="text-right">Qté chargée</th>
                <th class="text-right">Qté vendue</th>
                <th class="text-right">Qté retournée</th>
                <th class="text-right">Résultat</th>
                <th class="text-right">Prix</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalLoaded = 0;
                $totalSold = 0;
                $totalReturned = 0;
                $totalResult = 0;
                $totalPrice = 0;
            @endphp
            @foreach($items as $item)
                @php
                    $result = $item->total_sold + $item->total_returned - $item->total_loaded;
                    $price = $result * $item->product->price;
                    $totalLoaded += $item->total_loaded;
                    $totalSold += $item->total_sold;
                    $totalReturned += $item->total_returned;
                    $totalResult += $result;
                    $totalPrice += $price;
                @endphp
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td class="text-right">{{ $item->total_loaded }}</td>
                    <td class="text-right">{{ $item->total_sold }}</td>
                    <td class="text-right">{{ $item->total_returned }}</td>
                    <td class="text-right result {{ $result < 0 ? 'negative' : 'success' }}">
                                {{  $result >0? "+":""}}{{ $result }}
                    </td>
                    <td class="text-right price {{ $price < 0 ? 'negative' : 'success' }}">
                        {{ number_format($price, 0, ',', ' ') }} F
                    </td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td><strong>TOTAL</strong></td>
                <td class="text-right">{{ $totalLoaded }}</td>
                <td class="text-right">{{ $totalSold }}</td>
                <td class="text-right">{{ $totalReturned }}</td>
                <td class="text-right result {{ $totalResult < 0 ? 'negative' : 'success' }}">
                        {{ $totalResult >0? "+":""}}{{ $totalResult }}
                </td>
                <td class="text-right price {{ $totalPrice < 0 ? 'negative' : 'success' }}">
                    {{ number_format($totalPrice, 0, ',', ' ') }} F
                </td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>Document généré le {{ $date }}</p>
        @if($inventory->closed)
        <p><strong>Inventaire clôturé</strong></p>
        @endif
    </div>
</body>
</html> 