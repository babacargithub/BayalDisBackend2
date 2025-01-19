<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lot de livraison - {{ $batch->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .section {
            margin-bottom: 20px;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        .status-box {
            padding: 10px;
            border-radius: 5px;
            text-align: center;
        }
        .delivered { background-color: #f0fdf4; }
        .pending { background-color: #fefce8; }
        .cancelled { background-color: #fef2f2; }
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
            background-color: #f3f4f6;
        }
        .customer-address {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Lot de livraison - {{ $batch->name }}</h1>
        <p>Date de livraison: {{ $batch->delivery_date ? date('d/m/Y', strtotime($batch->delivery_date)) : 'Non définie' }}</p>
        <p>Livreur: {{ $batch->livreur ? $batch->livreur->name : 'Non assigné' }}</p>
    </div>

    <div class="section">
        <h2>Total par produit</h2>
        <table>
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Quantité totale</th>
                </tr>
            </thead>
            <tbody>
                @foreach($productTotals as $product => $data)
                <tr>
                    <td>{{ $product }}</td>
                    <td>{{ $data['quantity'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Liste des commandes</h2>
        <table>
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Produit</th>
                    <th>Quantité</th>
                </tr>
            </thead>
            <tbody>
                @foreach($batch->orders as $order)
                <tr>
                    <td>
                        {{ $order->customer->name }}
                        <div class="customer-address">{{ $order->customer->address }}</div>
                    </td>
                    <td>{{ $order->product->name }}</td>
                    <td>{{ $order->quantity }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html> 