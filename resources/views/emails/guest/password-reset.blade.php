<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reset your password</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #222;">
    <p>Hi {{ $customerName }},</p>
    <p>We received a request to reset the password for your guest account.</p>
    <p>
        <a href="{{ $resetUrl }}" style="display: inline-block; padding: 10px 16px; background: #135846; color: #fff; text-decoration: none; border-radius: 6px;">
            Reset password
        </a>
    </p>
    <p>Or open this link: <br><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p>If you did not request a reset, you can ignore this email.</p>
</body>
</html>
