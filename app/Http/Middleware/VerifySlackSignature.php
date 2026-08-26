<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica che le richieste su /slack/* arrivino davvero da Slack.
 *
 * Slack firma ogni richiesta con HMAC-SHA256 calcolato su "v0:{timestamp}:{raw_body}"
 * usando il Signing Secret dell'app (Basic Information → App Credentials).
 * Vedi https://api.slack.com/authentication/verifying-requests-from-slack
 */
class VerifySlackSignature
{
    /** Oltre questa finestra una richiesta (anche se firmata correttamente) viene rifiutata: previene replay attack. */
    private const MAX_TIMESTAMP_SKEW_SECONDS = 60 * 5;

    public function handle(Request $request, Closure $next): Response
    {
        $signingSecret = (string) config('services.slack.signing_secret');
        if ($signingSecret === '') {
            abort(500, 'SLACK_SIGNING_SECRET non configurato.');
        }

        $timestamp = $request->header('X-Slack-Request-Timestamp', '');
        $signature = $request->header('X-Slack-Signature', '');

        if ($timestamp === '' || $signature === '') {
            abort(401, 'Richiesta priva delle intestazioni di firma Slack.');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            abort(401, 'Timestamp della richiesta Slack troppo vecchio.');
        }

        // Il body raw (non parsato) è quello su cui Slack calcola la firma.
        $baseString = 'v0:' . $timestamp . ':' . $request->getContent();
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            abort(401, 'Firma Slack non valida.');
        }

        return $next($request);
    }
}
