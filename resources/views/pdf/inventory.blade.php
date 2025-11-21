<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Inventaire - {{ $carLoad->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .info { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; }
        .result { font-weight: bold; }
        .negative { color: red; }
        .footer { margin-top: 30px; text-align: center; font-size: 0.8em; }
        .total-row { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .price { text-align: right; }
    </style>
</head>
<body>
<div class="header">
    <h1>Inventaire - {{ $carLoad->name }}</h1>
    <p>{{ $inventory->name }}</p>
</div>

<div class="info">
    <p><strong>Responsable du véhicule :</strong> {{ $carLoad->team->manager->name}}</p>
    <p>
        <strong>Date Chargement:</strong> {{ $carLoad->load_date?->format('d/m/Y') }}
        <strong>&nbsp;Date déchargement:</strong> {{ $carLoad->return_date?->format('d/m/Y') }}
        <strong>&nbsp;Date d'inventaire:</strong> {{ $date }}
    </p>
    <p><strong>Généré par par:</strong> {{ $inventory->user->name }}</p>
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
    @foreach($items as $item)
        <tr>
            <td>{{ $item['product_name'] }}</td>
            <td class="text-right">{{ $item['total_loaded'] }}</td>
            <td class="text-right">
                {{ intval($item['total_sold']) }} cartons<br>
                @if(!empty($item['packets_per_carton']))
                    {{ $item['sold_packets'] }} paquets
                @endif
            </td>
            <td class="text-right">
                {{ $item['total_returned'] }} Cartons <br>
                @foreach($item['children'] as $child)
                    {{ $child->product->name }} : {{ $child->total_returned }}<br>
                @endforeach
            </td>
            <td class="text-right result {{ $item['result'] < 0 ? 'negative' : 'success' }}">
                @if($item['result_cartons'] == 0 && $item['result_packets'] == 0)
                    <span class="success" style="color: #00c853">Décompte OK</span>
                @else
                    {{ $item['result_sign'] =='-'?'Manque ':'Surplus de ' }} {{ $item['result_cartons'] > 0
                    ?'cartons':''
                     }}
                    @isset($item['packets_per_carton'])
                        @if($item['packets_per_carton']) et {{ $item['result_packets'] }} paquets
                        @endif
                    @endisset
                @endif
            </td>
            <td class="text-right price {{ $item['price'] < 0 ? 'negative' : 'success' }}">
                {{ number_format($item['price'], 0, ',', ' ') }} F
            </td>
        </tr>
    @endforeach
    <tr class="total-row">
        <td colspan="4" style="text-align: center"><strong>RESULTAT</strong></td>
        <td colspan="2" style="text-align: center" class="text-right price {{ $totalPrice < 0 ? 'negative' : 'success' }}">
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