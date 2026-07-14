<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Verify your account</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #222;">
    <p>Hi {{ $customerName }},</p>
    <p>Welcome! Your booking is confirmed in our system. Create a guest account so you can view bookings, invoices, and manage your stay.</p>
    <p><strong>Your verification code:</strong> <code style="font-size: 1.25rem; letter-spacing: 0.1em;">{{ $code }}</code></p>
    <p>
        <a href="{{ $verifyUrl }}" style="display: inline-block; padding: 10px 16px; background: #135846; color: #fff; text-decoration: none; border-radius: 6px;">
            Verify email &amp; continue
        </a>
    </p>
    <p>Or open this link: <br><a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a></p>
    <ol>
        <li>Open the link above (or paste it into your browser).</li>
        <li>Enter the 6-digit verification code.</li>
        <li>Create a password to activate your guest portal.</li>
    </ol>
    <p>If you did not make a booking, you can ignore this email.</p>
</body>
</html>
