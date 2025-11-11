@php use App\Models\CarLoadInventoryItem;use App\Models\Product; @endphp
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
    <p><strong>Responsable du véhicule :</strong> {{ $carLoad->team->manager->name}}</p>
    <p>
        <strong>Date Chargement:</strong> {{ $carLoad->load_date?->format("d/m/Y") }}<strong
        >&nbsp;Date déchargement:</strong> {{ $carLoad->return_date?->format("d/m/Y")
        }}<strong>&nbsp;Date
            d'inventaire:</strong> {{ $date }}</p>
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
    @php
        $totalLoaded = 0;
        $totalSold = 0;
        $totalReturned = 0;
        $totalResult = 0;
        $totalPrice = 0;
    @endphp
    @foreach($items as $item)
        @php
            $total_returned = $item->total_returned;
            foreach ($item->children as $childItem) {
                /** @var  CarLoadInventoryItem $childItem*/
                $total_returned+= $childItem->product->convertQuantityToParentQuantity($childItem->total_returned)
                ['decimal_parent_quantity'];

            }
            $result = $item->total_sold + $total_returned - $item->total_loaded;
            $result_parent = intval($item->total_sold);
            $result_variants = $item->total_sold - floor($item->total_sold);
            $price = 0;
            if (intval($result) > 0){
                $price = intval($result) * $item->product->price;
            }else if ($result > 0){
                $first_variant = $item->children->first();
                if ($first_variant != null){
                    $price =  ($result_variants * ($item->product->base_quantity /
                ($first_variant?->product->base_quantity ?? 1))
                ?? 1) * $first_variant->price ;
                }else{
                    //TODO fix this
                $price = 0;
                }
            }else{
                $price = $result * $item->product->price;
            }
//            $totalLoaded += $item->total_loaded;
//            $totalSold += $item->total_sold;
//            $totalReturned += $item->total_returned;
//            $totalResult += $result;
            $totalPrice += $price;
        @endphp
        <tr>
            <td>{{ $item->product->name }}</td>
            <td class="text-right">{{ $item->total_loaded }}</td>
            <td class="text-right">
                {{round($item->totalSold,2)}}
                Cartons <br>
                @if($item->children->first())
                    {{round( $result_variants * ($item->product->base_quantity / $item->children->first()
                ?->product->base_quantity ?? 0))}} paquets
                @endif
            </td>
            <td class="text-right">{{ $item->total_returned }}
                @foreach($item->children as $child)
                    {{ $child->product->name }} :
                    {{ $child->total_returned }}<br>
                @endforeach
            </td>
            <td class="text-right result {{ $result < 0 ? 'negative' : 'success' }}">
                {{  $result >0? "+":""}}{{ $result * 50 }}
            </td>
            <td class="text-right price {{ $price < 0 ? 'negative' : 'success' }}">
                {{ number_format($price, 0, ',', ' ') }} F
            </td>
        </tr>
    @endforeach
    <tr class="total-row">
        <td colspan="4" style="text-align: center"><strong>RESULTAT</strong></td>
        <td colspan="2" style="text-align: center" class="text-right price  {{ $totalPrice < 0 ? 'negative' :
        'success' }}">
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