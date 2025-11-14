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
            $totalReturned = $item->total_returned;
            foreach ($item->children as $childItem) {
                /** @var  CarLoadInventoryItem $childItem*/
                $totalReturned += $childItem->product->convertQuantityToParentQuantity($childItem->total_returned)
                ['decimal_parent_quantity'];
//                dump($total_returned);
            }

            // Result as decimal number of parent cartons (sold + returned - loaded)
            $result = $item->total_sold + $totalReturned - $item->total_loaded;

            // Split result into cartons and packets using first child as packet unit (if any)
            $first_variant = $item->children->first();
            $packets_per_carton = null;
            if ($first_variant) {
                $child_base_qty = $first_variant->product->base_quantity;
                $parent_base_qty = $item->product->base_quantity;
                $packets_per_carton = (int) floor($parent_base_qty / $child_base_qty);
                if ($packets_per_carton < 1) { $packets_per_carton = 1; }
            }

            $absResult = abs($result);
            $result_cartons = (int) floor($absResult);
            $fraction = $absResult - $result_cartons;
            $result_packets = 0;
            if ($packets_per_carton) {
                $result_packets = (int) round($fraction * $packets_per_carton);
                // Normalize carryover if rounding produced a full carton in packets
                if ($result_packets >= $packets_per_carton) {
                    $result_cartons += 1;
                    $result_packets = 0;
                }
            }

            // Price: number_of_cartons * price_carton + number_of_packets * price_packet (from first child)
            $price_per_carton = $item->product->price ?? 0;
            $price_per_packet = $first_variant?->price ?? 0;

            $price_abs = ($result_cartons * $price_per_carton) + ($result_packets * $price_per_packet);

            $price = ($item->total_sold + $totalReturned - $item->total_loaded ) * $item->product->price;
            $totalPrice += $price;

        @endphp
        <tr>
            <td>{{ $item->product->name }}</td>
            <td class="text-right">{{ $item->total_loaded }}</td>
            <td class="text-right">
                @php
                    $sold = $item->total_sold;
                    $sold_cartons = (int) floor($sold);
                    $sold_fraction = $sold - $sold_cartons;
                    $sold_packets = 0;
                    if (isset($packets_per_carton) && $packets_per_carton) {
                        $sold_packets = (int) round($sold_fraction * $packets_per_carton);
                        if ($sold_packets >= $packets_per_carton) {
                            $sold_cartons += 1;
                            $sold_packets = 0;
                        }
                    }
                @endphp
                {{ intval($sold) }} cartons<br>
                @if(isset($packets_per_carton) && $packets_per_carton)
                    {{ $sold_packets }} paquets
                @endif
            </td>
            <td class="text-right">{{ $item->total_returned }}
                @foreach($item->children as $child)
                    {{ $child->product->name }} :
                    {{ $child->total_returned }}<br>
                @endforeach
            </td>
            <td class="text-right result {{ $result < 0 ? 'negative' : 'success' }}">
                @php $sign = $result < 0 ? '-' : '+'; @endphp
                @if($result_cartons == 0 && $result_packets == 0)
                    <span class="success" style="color: #00c853">Conforme</span>
                    @else
                    {{ $sign }} {{ $result_cartons }} cartons{{ isset($packets_per_carton) && $packets_per_carton ? ' et ' . $result_packets . ' paquets' : '' }}
                @endif
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