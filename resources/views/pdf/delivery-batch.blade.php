<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lot de livraison - {{ $batch->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #2563eb;
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
            background-color: #f3f4f6;
        }
        .status-box {
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            font-size: 12px;
        }
        .status-delivered { background-color: #dcfce7; color: #166534; }
        .status-waiting { background-color: #fef9c3; color: #854d0e; }
        .status-cancelled { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Lot de livraison - {{ $batch->name }}</h1>
        <p>Date de livraison: {{ $batch->delivery_date ? date('d/m/Y', strtotime($batch->delivery_date)) : 'Non définie' }}</p>
        <p>Livreur: {{ $batch->livreur ? $batch->livreur->name : 'Non assigné' }}</p>
    </div>

    <div class="section">
        <div class="section-title">Statistiques par statut</div>
        <table>
            <tr>
                <th>Statut</th>
                <th>Nombre de commandes</th>
                <th>Quantité totale</th>
            </tr>
            <tr>
                <td>Livrées</td>
                <td>{{ $statusTotals['DELIVERED']['count'] }}</td>
                <td>{{ $statusTotals['DELIVERED']['quantity'] }}</td>
            </tr>
            <tr>
                <td>En attente</td>
                <td>{{ $statusTotals['WAITING']['count'] }}</td>
                <td>{{ $statusTotals['WAITING']['quantity'] }}</td>
            </tr>
            <tr>
                <td>Annulées</td>
                <td>{{ $statusTotals['CANCELLED']['count'] }}</td>
                <td>{{ $statusTotals['CANCELLED']['quantity'] }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Total par produit</div>
        <table>
            <tr>
                <th>Produit</th>
                <th>Quantité totale</th>
                <th>Livrées</th>
                <th>En attente</th>
                <th>Annulées</th>
            </tr>
            @foreach($productTotals as $total)
            <tr>
                <td>{{ $total['name'] }}</td>
                <td>{{ $total['total_quantity'] }}</td>
                <td>{{ $total['by_status']['DELIVERED'] }}</td>
                <td>{{ $total['by_status']['WAITING'] }}</td>
                <td>{{ $total['by_status']['CANCELLED'] }}</td>
            </tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <div class="section-title">Liste des commandes</div>
        <table>
            <tr>
                <th>Client</th>
                <th>Produits</th>
                <th>Total</th>
                <th>Statut</th>
            </tr>
            @foreach($batch->orders as $order)
            <tr>
                <td>
                    {{ $order->customer->name }}<br>
                    <small>{{ $order->customer->phone_number }}</small><br>
                    <small>{{ $order->customer->address }}</small>
                </td>
                <td>
                    @foreach($order->items as $item)
                    {{ $item->product->name }} - {{ $item->quantity }} unité(s)<br>
                    Prix unitaire: {{ number_format($item->price) }} FCFA<br>
                    @endforeach
                </td>
                <td>{{ number_format($order->total_price) }} FCFA</td>
                <td>
                    <div class="status-box status-{{ strtolower($order->status) }}">
                        {{ $order->status === 'DELIVERED' ? 'Livrée' : 
                           ($order->status === 'WAITING' ? 'En attente' : 'Annulée') }}
                    </div>
                </td>
            </tr>
            @endforeach
        </table>
    </div>
</body>
</html> 