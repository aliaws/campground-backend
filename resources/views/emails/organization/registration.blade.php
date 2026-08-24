<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate your organization</title>
</head>
<body style="margin:0; padding:0; background-color:#f2f4f3; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f4f3;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 2px 10px rgba(19,88,70,0.08);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#135846; background-image:linear-gradient(135deg,#135846,#1d7259); padding:28px 32px;">
                            <span style="font-size:20px; font-weight:700; color:#ffffff; letter-spacing:0.2px;">
                                &#127966; Campground Rentals
                            </span>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 32px 8px;">
                            <p style="margin:0 0 4px; font-size:13px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#c97a2b;">Welcome</p>
                            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; color:#111827;">Let's activate your organization</h1>
                            <p style="margin:0 0 20px; font-size:15px; color:#4b5563;">Hi {{ $ownerName }},</p>
                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563;">
                                Thanks for installing the app for {{ $organizationName }}. Verify this email address to
                                activate your organization and set up your owner account.
                            </p>

                            <!-- Code box -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td align="center" style="background-color:#fbf3ea; border:1px solid #f0ddc4; border-radius:12px; padding:20px;">
                                        <p style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#c97a2b;">Your verification code</p>
                                        <p style="margin:0; font-size:32px; font-weight:700; letter-spacing:0.3em; color:#111827; font-family: 'Courier New', monospace;">{{ $code }}</p>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA button -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $verifyUrl }}" style="display:inline-block; padding:13px 28px; background-color:#135846; color:#ffffff; text-decoration:none; border-radius:8px; font-size:15px; font-weight:600;">
                                            Verify email &amp; continue
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 24px; font-size:13px; color:#9ca3af; text-align:center; word-break:break-all;">
                                Or paste this link into your browser:<br>
                                <a href="{{ $verifyUrl }}" style="color:#135846;">{{ $verifyUrl }}</a>
                            </p>

                            <!-- Steps -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; border-radius:12px; margin:0 0 8px;">
                                <tr>
                                    <td style="padding:20px 24px;">
                                        <p style="margin:0 0 12px; font-size:13px; font-weight:600; color:#374151;">Next steps</p>
                                        <ol style="margin:0; padding-left:18px; font-size:14px; color:#4b5563; line-height:1.8;">
                                            <li>Open the link above (or paste it into your browser).</li>
                                            <li>Enter the 6-digit verification code.</li>
                                            <li>Create your owner password to activate the organization.</li>
                                        </ol>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px 32px;">
                            <hr style="border:none; border-top:1px solid #e5e7eb; margin:0 0 20px;">
                            <p style="margin:0; font-size:12px; color:#9ca3af; line-height:1.6;">
                                If you did not request this, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
