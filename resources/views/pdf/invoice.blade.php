<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Facture #{{ $invoice->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        .container {
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .company-info {
            float: left;
            width: 50%;
        }
        .customer-info {
            float: right;
            width: 50%;
            text-align: right;
        }
        .invoice-title {
            clear: both;
            color: blue;
            text-align: center;
            font-size: 24px;
            margin: 40px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: blue;
            color: white;
        }
        .total-row {
            font-weight: bold;
        }
        .payment-status {
            margin-top: 20px;
            font-weight: bold;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            
            <div class="company-info">
                <img src="{{asset("logo.jpg")}}" alt="Bayal Services" style="width: 100px; height: 100px;">
                <div class="company-name">Bayal Services</div>
                <div>Adresse: Route de l'aéroport, DAKAR</div>
                <div>Contacts: 777761935/773300853</div>
                <div>Email: bayalservices@gmail.com</div>
                <div>Site web: bayalservices.com</div>
            </div>
            <div class="customer-info">
                <div><strong>{{ $invoice->customer->name }}</strong></div>
                <div>{{ $invoice->customer->address ?: 'N/A' }}</div>
                <div>{{ $invoice->customer->phone_number ?: 'N/A' }}</div>
            </div>
        </div>

        <div class="invoice-title">
            Facture de vente Nº {{ $invoice->id }}
        </div>

        <table>
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->price, 0, ',', ' ') }} FCFA</td>
                    <td>{{ number_format($item->subtotal, 0, ',', ' ') }} FCFA</td>
                </tr>
                @endforeach
                <!--  if invoice has been partially paid -->
                 <tr class="total-row">
                    <td colspan="3" style="text-align: right">Total:</td>
                    <td>{{ number_format($invoice->total, 0, ',', ' ') }} FCFA</td>
                </tr>
                @if($invoice->total_paid < $invoice->total)
                <tr class="total-row">
                    <td colspan="3" style="text-align: right">Avance:</td>
                    <td>{{ number_format($invoice->total_paid, 0, ',', ' ') }} FCFA</td>

                <tr class="total-row" >
                    <td colspan="3" style="text-align: right">Reste à payer:</td>
                    <td>{{ number_format(($invoice->total - $invoice->total_paid), 0, ',', ' ') }} FCFA</td>
                </tr>
                @endif
              
            </tbody>
        </table>

        <div class="payment-status">
            
        
            @if($invoice->paid)
                <div style="color: green;">FACTUREE PAYÉE</div>
            @else
                <div style="color: red;">
                    À PAYER
                    @if($invoice->should_be_paid_at)
                        Echéance le {{ \Carbon\Carbon::parse($invoice->should_be_paid_at)->format('d/m/Y') }}
                    @endif
                </div>
            @endif
        </div>
    </div>
</body>
</html> 