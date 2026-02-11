<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt #{{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: #fff;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            margin-bottom: 40px;
            border-bottom: 2px solid #059669; /* Success Green */
            padding-bottom: 20px;
            display: table;
            width: 100%;
        }
        .company-info {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #059669;
            margin-bottom: 8px;
        }
        .receipt-info {
            display: table-cell;
            width: 50%;
            text-align: right;
            vertical-align: top;
        }
        .receipt-title {
            font-size: 28px;
            font-weight: bold;
            color: #059669;
            margin-bottom: 8px;
        }
        .receipt-status {
            display: inline-block;
            padding: 4px 12px;
            background: #d1fae5;
            color: #059669;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .billing-section {
            margin: 30px 0;
            display: table;
            width: 100%;
        }
        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .bill-to, .payment-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .details-box {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .detail-row {
            margin-bottom: 8px;
            display: table;
            width: 100%;
        }
        .detail-label {
            display: table-cell;
            width: 40%;
            color: #666;
            font-size: 11px;
        }
        .detail-value {
            display: table-cell;
            width: 60%;
            font-weight: bold;
            color: #333;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        .items-table th {
            background: #059669;
            color: #fff;
            padding: 12px 15px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        .items-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .totals-section {
            float: right;
            width: 300px;
            margin-top: 20px;
        }
        .total-row {
            display: table;
            width: 100%;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .total-label {
            display: table-cell;
            width: 60%;
            color: #666;
        }
        .total-value {
            display: table-cell;
            width: 40%;
            text-align: right;
            font-weight: 500;
        }
        .paid-amount {
            background: #059669;
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .paid-amount .total-label,
        .paid-amount .total-value {
            color: #fff;
            font-size: 16px;
            font-weight: bold;
        }
        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 2px solid #059669;
            text-align: center;
            color: #888;
            font-size: 10px;
        }
        .stamp {
            position: absolute;
            top: 200px;
            right: 100px;
            width: 150px;
            height: 150px;
            border: 4px solid #059669;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #059669;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            transform: rotate(25deg);
            opacity: 0.2;
            z-index: -1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="stamp">PAID</div>

        <div class="header">
            <div class="company-info">
                <div class="company-name">{{ $invoice->team->name ?? config('app.name') }}</div>
                <div class="company-details">
                    Payment Receipt
                </div>
            </div>
            <div class="receipt-info">
                <div class="receipt-title">RECEIPT</div>
                <div class="receipt-status">Fully Paid</div>
            </div>
        </div>

        <div class="billing-section">
            <div class="bill-to">
                <div class="section-title">Received From</div>
                <div class="detail-value" style="font-size: 16px;">{{ $invoice->client->name }}</div>
                <div style="color: #666; margin-top: 4px;">
                    {{ $invoice->client->email }}<br>
                    {{ $invoice->client->address }}
                </div>
            </div>
            <div class="payment-details">
                <div class="section-title">Payment Info</div>
                <div class="detail-row">
                    <div class="detail-label">Receipt Number</div>
                    <div class="detail-value">REC-{{ $invoice->invoice_number }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Payment Date</div>
                    <div class="detail-value">{{ \Illuminate\Support\Carbon::parse($paymentDate)->format('M d, Y') }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Related Invoice</div>
                    <div class="detail-value">#{{ $invoice->invoice_number }}</div>
                </div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 70%;">Description</th>
                    <th style="width: 30%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Payment for Invoice #{{ $invoice->invoice_number }}</td>
                    <td>{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="totals-section">
            <div class="total-row">
                <div class="total-label">Invoice Total</div>
                <div class="total-value">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</div>
            </div>
            <div class="paid-amount">
                <div class="total-row" style="border: none; padding: 0;">
                    <div class="total-label">Amount Paid</div>
                    <div class="total-value">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</div>
                </div>
            </div>
        </div>

        <div style="clear: both;"></div>

        @if($note)
            <div style="margin-top: 40px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                <div class="section-title">Payment Notes</div>
                <div style="color: #666; font-size: 11px; font-style: italic;">{{ $note }}</div>
            </div>
        @endif

        <div class="footer">
            <p>Thank you for your prompt payment!</p>
            <p>This is a computer generated receipt and does not require a physical signature.</p>
            <p>Generated on {{ now()->format('M d, Y \a\t h:i A') }}</p>
        </div>
    </div>
</body>
</html>
