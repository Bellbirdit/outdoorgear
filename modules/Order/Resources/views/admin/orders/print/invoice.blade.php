<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>{{ trans('order::print.invoice') }}</title>
    <style>
        :root{
            --bg:#f6f8fb;
            --card:#fff;
            --muted:#6b7280;
            --accent:#0f172a;
            --border:#e6e9ef;
            --success:#10b981;
        }
        /* Reset & base */
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0;
            font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
            background:var(--bg);
            color:#111827;
            -webkit-font-smoothing:antialiased;
            -moz-osx-font-smoothing:grayscale;
            padding:24px;
        }
        /* Container */
        .invoice-wrap{
            max-width:900px;
            margin:0 auto;
            background:var(--card);
            border-radius:12px;
            box-shadow:0 6px 18px rgba(12,17,24,0.08);
            padding:28px;
            /*border:1px solid var(--border);*/
        }
        header.invoice-header{
            display:flex;
            justify-content:space-between;
            gap:16px;
            align-items:flex-start;
            margin-bottom:18px;
        }
        .brand{
            display:flex;
            gap:0px;
            align-items:center;
        }
        .brand img{height:56px; width:auto; border-radius:8px; object-fit:contain}
        .brand h1{font-size:20px; margin:0; letter-spacing:0.2px}
        .brand small{display:block; color:var(--muted); font-size:10px}
        .meta{
            text-align:left;
            min-width:220px;
        }
        .meta .label{display:block; color:var(--muted); font-size:10px}
        .meta .value{font-weight:700; font-size:12px}
        /* Addresses and details */
        .grid{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:0px;
            align-items:start;
            margin-bottom:22px;
        }
        .card{
            background:linear-gradient(180deg, rgba(255,255,255,1), rgba(255,255,255,0.97));
            /*border:1px solid var(--border);*/
            padding:14px;
            border-radius:8px;
        }
        .card h3{margin:0 0 8px 0; font-size:12px}
        .muted{color:var(--muted); font-size:10px; line-height:1.45}
        /* Table */
        table{
            width:100%;
            border-collapse:collapse;
            margin-bottom:18px;
            font-size:12px;
        }
        thead th{
            text-align:left;
            padding:0px 0x;
            /*border-bottom:1px solid var(--border);*/
            color:var(--muted);
            font-weight:900;
            font-size:12px;
        }
        th{text-align: left;}
        tbody td{
            padding:0px 0px;
            /*border-bottom:1px dashed var(--border);*/
            vertical-align:top;
        }
        tbody tr:last-child td{border-bottom:0}
        .text-right{text-align:right}
        .unit{width:90px}
        .qty{width:70px}
        .price{width:120px}
        /* totals */
        .totals{
            display:flex;
            justify-content:flex-end;
            gap:0px;
            margin-top:8px;
        }
        .totals .summary{
            width:320px;
            background:transparent;
            border-radius:8px;
            padding:10px;
        }
        .summary-row{display:flex;justify-content:space-between;padding:0px 2px; font-size:11px;text-align:right;}
        .summary-row.total{font-weight:600;font-size:11px !important; border-top:1px solid var(--border); 
        padding-top:0px;margin-top:6px}
        /* footer / notes*/
        .notes{margin-top:18px; color:var(--muted); font-size:10px}
        footer{margin-top:22px; text-align:center; color:var(--muted); font-size:10px}
        /* Print */
        @media print {
            body{padding:0;background:white}
            .invoice-wrap{box-shadow:none;border-radius:0;padding:12px;border:0;max-width:100%}
            header.invoice-header{flex-direction:row}
            .meta{text-align:left}
            .grid{grid-template-columns:1fr 1fr}
            a[href]:after{content:" (" attr(href) ")";}
        }
        /* Responsive */
        @media (max-width:720px){
            .grid{grid-template-columns:1fr; gap:12px}
            .meta{min-width:0; text-align:left}
            header.invoice-header{flex-direction:column; align-items:flex-start; gap:12px}
            .brand h1{font-size:18px}
            .totals{justify-content:center}
            .totals .summary{width:100%}
        }
        .inv_tbl tr td {
            /*border-top: 1px solid #f1f1f1;*/
            color: #444;
            padding: 10px 0 10px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="invoice-wrap" role="document" aria-label="Invoice document">
        <header class="invoice-header">
            <div class="brand">
                <!-- Replace src with your logo image from the PDF or remove img tag if not needed -->
                <!-- <img src="logo.png" alt="Company logo (replace with your image)" /> -->
                <div>
                    <h1>{{ setting('store_name') }}</h1>
                    <small class="muted">Address line 1 · Address line 2 · City, PIN</small>
                    <small class="muted">TRN: 12ABCDE3456F7Z8 · Phone: +91-9876543210</small>
                </div>
            </div>
            <div class="meta" aria-hidden="false">
                <h2 style="margin: 0px; padding: 0px;">Tax Invoice</h2>
                
               
            </div>
        </header>

        <section >
            <h2 style="margin: 0px; padding: 0px;">Order Details</h2>
            <table role="table" aria-label="Invoice line items">
            <tr>
                <th >Order Id</th>
                <td >{{ $order->id }}</td>
                <th style="text-align: right;">Invoice :</th>
                <td style="padding-left: 15px;">#{{ $order->id }}</td>
               
            </tr>
            <tr>
                <th>Name</th>
                <td>{{ $order->billing_full_name }}</td>
                <th style="text-align: right;">Issue Date :</th>
                <td style="padding-left: 15px;">{{ $order->created_at->format('Y / m / d') }}</td>
               
                
            </tr>
            <tr>
                <th>email</th>
                <td>{{ $order->customer_email }}</td>
                 <th style="text-align: right;">Shipping method :</th>
                <td style="padding-left: 15px;">free shipping</td>
                
                
            </tr>
            <tr>
                <th>phone</th>
                <td>{{ $order->customer_phone }}</td>
                 <th style="text-align: right;">Payment method :</th>
                <td style="padding-left: 15px;">Cash on delevry</td>
            </tr>
            <br>
            <tr>
                <th>Billing Address </th>
                <td  colspan="3">{{ $order->billing_address_1 }}, {{ $order->billing_address_2 }}, {{ $order->billing_city }}, {{ $order->billing_state_name}} {{ $order->billing_country_name }}</td>
            </tr>
            <tr>
                <th>shipping Address</th>
                <td colspan="3">{{ $order->shipping_address_1 }}, {{ $order->shipping_address_2 }}, {{ $order->shipping_city }}, {{ $order->shipping_state_name }} {{ $order->shipping_country_name }}</td>
            </tr>
            <!-- If you have discounts, taxes, or adjustments, add rows here -->
        </section>
        <section aria-label="Invoice items">
            <table role="table" aria-label="Invoice line items" class="inv_tbl">
                <thead>
                    <tr style="font-size:12px">
                        <th style="width:40%">Products</th>
                        <th class="qty" style="text-align: right">Qty</th>
                        <th class="unit" style="text-align: right">Unit Price(inc vat)</th>
                        <th class="price text-right" style="text-align: right">Total Amount (inc.Vat)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->products as $product)
                    <tr>
                        <td>
                            <strong>{{ $product->name }}</strong><br/>
                            <!-- <small class="muted">Short description: specs, period, HSN/SAC code: 9985</small> -->
                            <small class="muted">
                            @if($product->hasAnyVariation())
                                <div class="option">
                                    @foreach ($product->variations as $variation)
                                        <span>
                                            {{ $variation->name }}:

                                            <span>
                                                {{ $variation->values()->first()?->label }}{{ $loop->last ? '' : ',' }}
                                            </span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($product->hasAnyOption())
                                <div class="option">
                                    @foreach ($product->options as $option)
                                        <span>
                                            {{ $option->name }}:

                                            <span>
                                                @if ($option->option->isFieldType())
                                                    {{ $option->value }}
                                                @else
                                                    {{ $option->values->implode('label', ', ') }}
                                                @endif
                                            </span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            </small>
                        </td>

                        <td style="text-align: right">
                            <!-- <label class="visible-xs">{{ trans('order::print.quantity') }}:</label> -->
                            <span>{{ $product->qty }}</span>
                        </td>

                        <td style="text-align: right">
                            <!-- <label class="visible-xs">{{ trans('order::print.unit_price') }}:</label> -->
                            <span>{{ $product->unit_price->convert($order->currency, $order->currency_rate)->convert($order->currency, $order->currency_rate)->format($order->currency) }}</span>
                        </td>

                        <td style="text-align: right">
                            <!-- <label class="visible-xs">{{ trans('order::print.line_total') }}:</label> -->
                            <span>{{ $product->line_total->convert($order->currency, $order->currency_rate)->format($order->currency) }}</span>
                        </td>
                    </tr>
                    @endforeach
                    <!-- If you have discounts, taxes, or adjustments, add rows here -->
                </tbody>
            </table>
            @php
            $finaltotal = $order->total->amount();
            $subtotal = ($finaltotal * 100/105);
            $vatprice = $finaltotal - $subtotal;

            $subtotalMoney = new \Modules\Support\Money($subtotal, $order->currency);
            $vatpriceMoney = new \Modules\Support\Money($vatprice, $order->currency);
            @endphp
            <div class="totals" aria-hidden="false">
                <div class="summary" role="note" aria-label="Totals summary">
                    <div class="summary-row"><span>Subtotal (excl vat)</span>
                    <strong>{{ $subtotalMoney->convert($order->currency, $order->currency_rate)->format($order->currency) }}</strong></div>
                    <div class="summary-row"><span>Vat (5%)</span><span>{{ $vatpriceMoney->convert($order->currency, $order->currency_rate)->format($order->currency) }}</span></div>
                    @if ($order->hasShippingMethod())
                    <div class="summary-row"><span>sipping</span><span>{{ $order->shipping_cost->convert($order->currency, $order->currency_rate)->format($order->currency) }}</span></div>
                    @endif
                    @if ($order->hasCoupon())
                    <div class="summary-row"><span>Discount</span><span>{{ $order->discount->convert($order->currency, $order->currency_rate)->format($order->currency) }}</span></div>
                    @endif
                    <div class="summary-row total"><span>Total (inc.Vat)</span><strong>{{ $order->total->convert($order->currency, $order->currency_rate)->format($order->currency) }}</strong></div>
                </div>
            </div>
        </section>
        <!--<section class="notes" aria-label="Notes and terms" style="margin-top: 30px;">-->
        <!--    <p><strong>Notes:</strong> Thank you for your business. Please pay by the due date. Late payments may attract interest.</p>-->
        <!--    <p><strong>Tax & Compliance:</strong> This invoice is generated from the source document uploaded by you (see source file). Replace the placeholder fields with exact values from your PDF if needed. Source: uploaded file. :contentReference[oaicite:1]{index=1}</p>-->
        <!--</section>-->
        <footer>
            <div>For queries regarding this invoice, contact accounts@company.com · Phone: +91-9876543210</div>
            <div style="margin-top:6px; font-size:12px">This is a computer-generated invoice and does not require a signature.</div>
        </footer>
    </div>

    <script type="module">
        window.print();
    </script>
</body>
</html>