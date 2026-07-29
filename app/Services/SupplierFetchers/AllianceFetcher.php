<?php

namespace App\Services\SupplierFetchers;

/**
 * Alliance Entertainment (AENT / WebAMI) portal fetcher — webami.aent.com.
 *
 * WebAMI is an ASP.NET MVC site: the login is a modal that POSTs
 * {__RequestVerificationToken, user-email-address, user-password} via jQuery
 * (no visible form action) and the site has TrustWave bot mitigation. The
 * exact login endpoint isn't in the page HTML, so debugProbe() tries the
 * likely ASP.NET routes using the saved encrypted creds and reports which one
 * authenticates — once we know the working endpoint + catalog page, fetch()
 * gets wired the rest of the way (like AMS/Redeye).
 *
 * Credentials (ICA Credentials form → encrypted on disk):
 *   ALLIANCE_PORTAL_USER — portal e-mail
 *   ALLIANCE_PORTAL_PASS — password
 */
class AllianceFetcher extends AbstractHttpFetcher
{
    protected string $base = 'https://webami.aent.com';

    public function supplierKey(): string { return 'alliance'; }

    public function readCredentials(): array
    {
        // URL is fixed; only user + pass needed (mirrors AMS/Redeye).
        return $this->requireEnv(['ALLIANCE_PORTAL_USER', 'ALLIANCE_PORTAL_PASS']);
    }

    public function fetch(): array
    {
        throw new \RuntimeException(
            'Alliance auto-fetch not fully wired yet — WebAMI login is JS-driven + bot-protected. '
            . 'Run the diagnostics page to probe the login endpoint (ALLIANCE DEBUG), or upload an '
            . 'Alliance availability xlsx via the supplier-feeds widget in the meantime.'
        );
    }

    /** ASP.NET anti-forgery token from the login page. */
    protected function extractToken(string $html): ?string
    {
        if (preg_match('#name="__RequestVerificationToken"[^>]*value="([^"]+)"#', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * On-server probe (ICA diagnostics): using the saved creds, GET the site to
     * seed cookies + token, then try each candidate login endpoint and report
     * which returns a logged-in state. Never returns the password.
     *
     * @return array<int,string>
     */
    public function debugProbe(): array
    {
        $out = [];
        try {
            $creds = $this->readCredentials();
            $home = $this->get($this->base . '/');
            $token = $this->extractToken($home);
            $out[] = 'anti-forgery token on /: ' . ($token ? 'found' : 'MISSING');
            $out[] = 'home logged-in markers: ' . $this->markers($home);

            $candidates = [
                '/account/logon', '/account/login', '/Account/LogOn', '/Account/Login',
                '/user/login', '/user/logon', '/login', '/webami/login', '/account/authenticate',
            ];
            foreach ($candidates as $path) {
                $fields = [
                    '__RequestVerificationToken' => (string) $token,
                    'user-email-address' => $creds['ALLIANCE_PORTAL_USER'],
                    'user-password' => $creds['ALLIANCE_PORTAL_PASS'],
                    'user-consumer-mode' => 'false',
                ];
                try {
                    $resp = $this->login($this->base . $path, $fields);
                    $out[] = sprintf('  POST %-24s -> len=%d %s', $path, strlen($resp), $this->markers($resp));
                } catch (\Throwable $e) {
                    $out[] = sprintf('  POST %-24s -> %s', $path, $e->getMessage());
                }
            }
            // After the attempts, re-GET home to see if any established a session.
            $after = $this->get($this->base . '/');
            $out[] = 'home after attempts: ' . $this->markers($after);
        } catch (\Throwable $e) {
            $out[] = 'PROBE ERROR: ' . $e->getMessage();
        }
        return $out;
    }

    /** Compact logged-in/out signal for a WebAMI response body. */
    protected function markers(string $html): string
    {
        $sig = [];
        foreach (['sign out', 'signout', 'logout', 'log out', 'my account', 'your price', 'availability'] as $m) {
            if (stripos($html, $m) !== false) $sig[] = $m;
        }
        $loggedOut = (stripos($html, 'user-password') !== false || stripos($html, 'user-email-address') !== false);
        return 'in[' . implode(',', $sig) . ']' . ($loggedOut ? ' loginFormPresent' : '');
    }
}
