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
        .small { font-size: 0.85em; }
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
            <td class="text-right">
                @php($loadedDisplay = $item['product']->getFormattedDisplayOfCartonAndParquets($item['total_loaded']))
                {{ $loadedDisplay['cartons'] }} cartons<br>
                @if(($loadedDisplay['paquets'] ?? 0) > 0)
                    <span class="small">{{ $loadedDisplay['paquets'] }} paquets
{{--                        @if(!empty($loadedDisplay['first_variant_name'])) de {{ $loadedDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right">
                @php($soldDisplay = $item['product']->getFormattedDisplayOfCartonAndParquets($item['total_sold']))
                {{ $soldDisplay['cartons'] }} cartons<br>
                @if(($soldDisplay['paquets'] ?? 0) > 0)
                    <span class="small">{{ $soldDisplay['paquets'] }} paquets
{{--                        @if(!empty($soldDisplay['first_variant_name'])) de {{ $soldDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right">


                    <table class="nested-table">
                        @foreach($item['children'] as $child)
                            <tr><td class="highlight-a">{{ $child->product->name }} </td>
                                <td>
                                    {{ $child->total_returned }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                {{'sois'}}<br>
                @php($returnedDisplay = $item['product']->getFormattedDisplayOfCartonAndParquets($item['total_returned']))
                {{ $returnedDisplay['cartons'] }} cartons<br>
                @if(($returnedDisplay['paquets'] ?? 0) > 0)
                    <span class="small">{{ $returnedDisplay['paquets'] }} paquets
{{--                        @if(!empty($returnedDisplay['first_variant_name'])) de {{ $returnedDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right result {{ $item['result'] < 0 ? 'negative' : 'success' }}">
                @php($absResult = abs($item['result']))
                @php($resultDisplay = $item['product']->getFormattedDisplayOfCartonAndParquets($absResult))
                @if(($resultDisplay['cartons'] ?? 0) == 0 && ($resultDisplay['paquets'] ?? 0) == 0)
                    <span class="success" style="color: #00c853">Décompte OK</span>
                @else
                    {{ $item['result'] < 0 ? 'Manque ' : 'Surplus de ' }}
                    @if(($resultDisplay['cartons'] ?? 0) > 0)
                        {{ $resultDisplay['cartons'] }} cartons
                    @endif
                    @if(($resultDisplay['paquets'] ?? 0) > 0)
                        @if(($resultDisplay['cartons'] ?? 0) > 0)
                            et
                        @endif
                        <span class="small">{{ $resultDisplay['paquets'] }} paquets @if(!empty($resultDisplay['first_variant_name'])) de {{ $resultDisplay['first_variant_name'] }} @endif</span>
                    @endif
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