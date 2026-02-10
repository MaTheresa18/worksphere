<!DOCTYPE html>
<html>
<head>
    <title>{{ $title ?? 'Connection Status' }}</title>
    <style nonce="{{ $nonce ?? '' }}">
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
            text-align: center; 
            padding: 40px; 
            color: #1f2937; 
            background-color: #f9fafb;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .success-icon { 
            width: 64px;
            height: 64px;
            background-color: #d1fae5;
            color: #059669;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            font-size: 32px;
        }
        .error-icon {
            width: 64px;
            height: 64px;
            background-color: #fee2e2;
            color: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            font-size: 32px;
        }
        h3 { margin: 0 0 8px; font-size: 24px; font-weight: 700; }
        p { margin: 0; color: #6b7280; }
        .countdown { font-weight: 600; color: #4b5563; margin-top: 16px; }
        .manual-close { margin-top: 32px; padding: 10px 20px; background: #e5e7eb; border-radius: 8px; cursor: pointer; border: none; font-weight: 500; color: #374151; transition: background 0.2s; }
        .manual-close:hover { background: #d1d5db; }
    </style>
</head>
<body>
    @if($status === 'error')
        <div id="oauth-status" data-status="error" data-message="{{ $message }}"></div>
        <div class="error-icon">✕</div>
        <h3>Connection Failed</h3>
        <p>{{ $message }}</p>
        <button id="manual-close" class="manual-close">Close Window</button>
    @else
        <div id="oauth-status" data-status="success" data-account-id="{{ $accountId }}" data-operation-status="{{ $operationStatus }}" data-email="{{ $email ?? '' }}"></div>
        <div class="success-icon">✓</div>
        <h3>Account Connected!</h3>
        <p>Your email has been successfully linked.</p>
        <div class="countdown" id="timer">Closing in 3s...</div>
        <button id="manual-close" class="manual-close">Close Window</button>
    @endif

    <script src="/js/email-oauth-callback.js?v={{ time() }}"></script>
</body>
</html>
