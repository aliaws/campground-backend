<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mail (SMTP) settings check — platform-global, superadmin only. Read-only
 * view of what config/mail.php currently resolves to (i.e. what the running
 * app actually loaded from .env — never the password itself), plus a "send
 * a test email" action that returns the real transport error so a wrong
 * .env value can be spotted and corrected. Nothing is editable here: the
 * mail settings live in .env, not the database.
 */
class MailSettingsController extends Controller
{
    /** Keeps a wrong host/port from hanging the request for the default 60s socket timeout. */
    private const TEST_TIMEOUT_SECONDS = 15;

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settings(),
        ]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc', 'max:255'],
        ], [], ['to' => 'email address']);

        $mailer = (string) config('mail.default');

        config(["mail.mailers.{$mailer}.timeout" => self::TEST_TIMEOUT_SECONDS]);
        Mail::purge($mailer);

        try {
            Mail::raw($this->testBody(), function (Message $message) use ($data) {
                $message->to($data['to'])->subject('Test email from '.config('app.name'));
            });
        } catch (Throwable $e) {
            Log::warning('Mail settings test email failed', [
                'mailer' => $mailer,
                'to' => $data['to'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Test email could not be sent.',
                'data' => [
                    'error' => $e->getMessage(),
                    'exception' => class_basename($e),
                    'hint' => $this->hintFor($e->getMessage()),
                    'settings' => $this->settings(),
                ],
            ], 422);
        }

        // "log"/"array" accept the message without delivering it anywhere,
        // so a clean send there proves nothing about SMTP.
        $delivers = ! in_array($mailer, ['log', 'array'], true);

        return response()->json([
            'success' => true,
            'message' => $delivers
                ? "Test email sent to {$data['to']}."
                : "The \"{$mailer}\" mailer accepted the message, but it does not deliver real email. Set MAIL_MAILER=smtp in .env to test SMTP.",
            'data' => [
                'delivered' => $delivers,
                'settings' => $this->settings(),
            ],
        ]);
    }

    private function settings(): array
    {
        $mailer = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$mailer}", []);

        return [
            'mailer' => $mailer,
            'transport' => $config['transport'] ?? null,
            'scheme' => $config['scheme'] ?? null,
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'username' => $config['username'] ?? null,
            'password_set' => filled($config['password'] ?? null),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            // With a cached config, editing .env has no effect until
            // `php artisan config:clear` (or config:cache) is run again.
            'config_cached' => app()->configurationIsCached(),
        ];
    }

    private function testBody(): string
    {
        return 'This is a test email from '.config('app.name').".\n\n"
            ."If you are reading this, the mail settings on the server are working.\n\n"
            .'Sent at: '.now()->toDayDateTimeString().' ('.config('app.timezone').')';
    }

    private function hintFor(string $error): ?string
    {
        $error = strtolower($error);

        return match (true) {
            str_contains($error, '421') || str_contains($error, 'try again later') || str_contains($error, 'server busy') => 'The mail server temporarily refused the connection (it is busy or is limiting this server\'s IP address). This is not a settings problem. Wait a few minutes and send the test again.',
            str_contains($error, '535') || str_contains($error, 'authenticat') || str_contains($error, 'username and password') => 'The mail server rejected the login. Check MAIL_USERNAME and MAIL_PASSWORD (Gmail needs an App Password, not the account password).',
            str_contains($error, 'timed out') || str_contains($error, 'connection refused') || str_contains($error, 'connection could not be established') || str_contains($error, 'unable to connect') => 'The server could not reach the mail host. Check MAIL_HOST and MAIL_PORT, and that the hosting provider allows outbound connections on that port.',
            str_contains($error, 'getaddrinfo') || str_contains($error, 'name or service not known') => 'The mail host name could not be resolved. Check MAIL_HOST.',
            str_contains($error, 'ssl') || str_contains($error, 'tls') || str_contains($error, 'certificate') || str_contains($error, 'crypto') => 'The secure connection failed. Check MAIL_SCHEME against MAIL_PORT (port 465 uses "smtps", port 587 uses "smtp").',
            str_contains($error, 'sender') || str_contains($error, 'from address') || str_contains($error, '553') || str_contains($error, '550') => 'The mail server refused the sender or recipient address. Check MAIL_FROM_ADDRESS is an address this account is allowed to send from.',
            default => null,
        };
    }
}
