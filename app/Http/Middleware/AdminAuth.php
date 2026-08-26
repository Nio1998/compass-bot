<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a singola password per il mini pannello di upload slide.
 * Non serve un vero sistema utenti: solo chi conosce ADMIN_PASSWORD può caricare/ingerire PDF.
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated') !== true) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
