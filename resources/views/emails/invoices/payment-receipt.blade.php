<x-mail::message>
# Payment Received

Hello {{ $invoice->client->name }},

Thank you for your payment for invoice **#{{ $invoice->invoice_number }}**.

We have received your payment on **{{ \Illuminate\Support\Carbon::parse($paymentDate)->format('M d, Y') }}**. Please find your payment receipt attached to this email.

**Invoice Summary:**
- **Invoice Number:** #{{ $invoice->invoice_number }}
- **Total Amount:** {{ $invoice->currency }} {{ number_format($invoice->total, 2) }}
- **Status:** Paid

<x-mail::button :url="config('app.url') . '/portal/invoices/' . $invoice->public_id">
View Invoice Online
</x-mail::button>

Thank you for your business!

Best regards,<br>
{{ $invoice->team->name ?? config('app.name') }}
</x-mail::message>
