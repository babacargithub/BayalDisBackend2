@php use App\Data\CarLoadInventory\CarLoadInventoryResultItemDTO; @endphp
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

        .small {
            font-size: 0.85em;
        }
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
        @php /** @var  CarLoadInventoryResultItemDTO $item */ @endphp
        <tr>
            <td>{{ $item->parent->name }}</td>
            <td class="text-right">
                {{ $item->totalLoadedConverted->parentQuantity }} cartons<br>
                @if($item->totalLoadedConverted->isMixed())
                    <span class="small">{{ $item->totalLoadedConverted->childQuantity }} paquets
{{--                        @if(!empty($loadedDisplay['first_variant_name'])) de {{ $loadedDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right">
                {{ $item->totalSoldConverted->parentQuantity }} cartons<br>
                @if($item->totalSoldConverted->isMixed())
                    <span class="small">{{ $item->totalSoldConverted->childQuantity }} paquets
{{--                        @if(!empty($soldDisplay['first_variant_name'])) de {{ $soldDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right">


                {{--<table class="nested-table">
                    @foreach($item->children as $child)
                        <tr>
                            <td class="highlight-a">{{ $child->name }} </td>
                            <td>
                                {{ $child->totalReturned }}
                            </td>
                        </tr>
                    @endforeach
                </table>--}}
                {{'sois'}}<br>
                {{ $item->totalReturnedConverted->parentQuantity }} cartons<br>
                @if($item->totalReturnedConverted->isMixed())
                    <span class="small">{{$item->totalReturnedConverted->childQuantity }} paquets
{{--                        @if(!empty($returnedDisplay['first_variant_name'])) de {{ $returnedDisplay['first_variant_name'] }} @endif--}}
                    </span>
                @endif
            </td>
            <td class="text-right result {{ $item->resultOfComputation < 0 ? 'negative' : 'success' }}">
                @php($absResult = abs($item->resultOfComputation ))
                @php($resultDisplay = $item->resultConverted)
                @if($resultDisplay->parentQuantity == 0 && $resultDisplay->childQuantity == 0)
                    <span class="success" style="color: #00c853">Décompte OK</span>
                @else
                    {{ $item->resultSign < 0 ? 'Manque ' : 'Surplus de ' }}
                        {{ $resultDisplay->parentQuantity }} cartons
                        <span class="small">{{ $resultDisplay->childQuantity }} paquets @if(!empty($resultDisplay->childName))
                                de {{  $resultDisplay->childName }}
                            @endif</span>

                @endif
            </td>
            <td class="text-right price {{ $item->priceOfResultComputation < 0 ? 'negative' : 'success' }}">
                {{ number_format($item->priceOfResultComputation, 0, ',', ' ') }} F
            </td>
        </tr>
    @endforeach
    <tr class="total-row">
        <td colspan="4" style="text-align: center"><strong>RESULTAT</strong></td>
        <td colspan="2" style="text-align: center"
            class="text-right price {{ $totalPrice < 0 ? 'negative' : 'success' }}">
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