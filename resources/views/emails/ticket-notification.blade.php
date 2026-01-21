<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f5;
            color: #18181b;
        }

        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .container {
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            padding: 24px;
            text-align: center;
        }

        .header img {
            max-height: 40px;
            margin-bottom: 12px;
        }

        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .content {
            padding: 32px 24px;
        }

        .greeting {
            font-size: 16px;
            margin-bottom: 16px;
        }

        .message {
            font-size: 15px;
            color: #52525b;
            margin-bottom: 24px;
        }

        .ticket-card {
            background-color: #fafafa;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .ticket-number {
            font-size: 13px;
            color: #6366f1;
            font-weight: 600;
        }

        .ticket-priority {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-low {
            background-color: #dcfce7;
            color: #166534;
        }

        .priority-medium {
            background-color: #fef3c7;
            color: #92400e;
        }

        .priority-high {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .priority-urgent {
            background-color: #fecaca;
            color: #7f1d1d;
        }

        .ticket-title {
            font-size: 16px;
            font-weight: 600;
            color: #18181b;
            margin-bottom: 8px;
        }

        .ticket-meta {
            font-size: 13px;
            color: #71717a;
        }

        .ticket-meta span {
            display: inline-block;
            margin-right: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            background-color: #e4e4e7;
            color: #52525b;
        }

        .sla-alert {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            color: #991b1b;
            font-size: 14px;
        }

        .sla-alert strong {
            display: block;
            margin-bottom: 4px;
        }

        .action-button {
            display: inline-block;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
        }

        .action-button:hover {
            opacity: 0.9;
        }

        .footer {
            padding: 24px;
            text-align: center;
            background-color: #fafafa;
            border-top: 1px solid #e4e4e7;
            font-size: 13px;
            color: #71717a;
        }

        .footer a {
            color: #6366f1;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .unsubscribe {
            margin-top: 16px;
            font-size: 12px;
            color: #a1a1aa;
        }

        .unsubscribe a {
            color: #a1a1aa;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="container">
            <!-- Header -->
            <div class="header">
                @if($appLogo)
                    <img src="{{ $appLogo }}" alt="{{ $appName }}">
                @else
                    <h1>{{ $appName }}</h1>
                @endif
            </div>

            <!-- Content -->
            <div class="content">
                <p class="greeting">Hello {{ $recipient->name }},</p>

                <p class="message">{{ $message }}</p>

                @if($type === 'sla_breach')
                    <div class="sla-alert">
                        <strong>⚠️ SLA Breach Alert</strong>
                        This ticket has exceeded its SLA threshold. Please take immediate action.
                    </div>
                @endif

                <!-- Ticket Card -->
                <div class="ticket-card">
                    <div class="ticket-header">
                        <span class="ticket-number">{{ $ticket->ticket_number }}</span>
                        <span class="ticket-priority priority-{{ strtolower($ticket->priority->value) }}">
                            {{ ucfirst($ticket->priority->value) }}
                        </span>
                    </div>
                    <div class="ticket-title">{{ $ticket->title }}</div>
                    <div class="ticket-meta">
                        <span>
                            <span class="status-badge">{{ ucfirst($ticket->status->value) }}</span>
                        </span>
                        @if($ticket->assignee)
                            <span>Assigned to: {{ $ticket->assignee->name }}</span>
                        @endif
                        @if($ticket->due_at)
                            <span>Due: {{ $ticket->due_at->format('M j, Y') }}</span>
                        @endif
                    </div>
                </div>

                <!-- Action Button -->
                <div style="text-align: center;">
                    <a href="{{ $actionUrl }}" class="action-button">{{ $actionText }}</a>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>This email was sent by <a href="{{ config('app.url') }}">{{ $appName }}</a></p>
                <div class="unsubscribe">
                    <a href="{{ $unsubscribeUrl }}">Manage notification preferences</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>