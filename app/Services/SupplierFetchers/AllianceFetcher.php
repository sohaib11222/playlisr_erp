<?php

namespace App\Services\SupplierFetchers;

/**
 * Alliance Entertainment (AENT / WebAMI) portal fetcher.
 *
 * Sarah 2026-05-28: confirmed Alliance is a SEPARATE distributor from
 * AMS (All Media Supply at allmediasupply.com). Alliance's B2B portal
 * is WebAMI at https://webami.aent.com/ — account-specific entry path.
 *
 * STATUS: scaffolded — portal walkthrough still needed. WebAMI 404s on
 * common login URLs from outside the network (TS-prefix cookie hints
 * at TrustWave bot mitigation). Once Sarah confirms the actual login
 * URL she uses, plumb the form fields here and implement the parser.
 *
 * Credentials saved via the supplier-feeds UI (encrypted on disk) read:
 *   ALLIANCE_PORTAL_URL    — actual /Login path inside WebAMI
 *   ALLIANCE_PORTAL_USER   — Sarah's portal username/email
 *   ALLIANCE_PORTAL_PASS   — password
 *   ALLIANCE_PRICES_URL    — catalog / availability endpoint after login
 *
 * Until the parser is in, manual xlsx upload via the supplier-feeds
 * widget populates the Alliance column the same way the AMS / Secretly
 * / etc. columns work today.
 */
class AllianceFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'alliance'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['ALLIANCE_PORTAL_URL', 'ALLIANCE_PORTAL_USER', 'ALLIANCE_PORTAL_PASS']);
    }

    public function fetch(): array
    {
        // Surface a friendly error so the inline "Fetch Alliance now"
        // button doesn't pretend to succeed. Sarah can still upload
        // Alliance prices manually via the supplier-feeds widget.
        throw new \RuntimeException(
            'Alliance auto-fetch not yet wired up — WebAMI portal entry path is account-specific. '
            . 'Upload an Alliance availability xlsx via the supplier-feeds widget for now; '
            . 'once we know your exact WebAMI login URL, the auto-fetch can be plumbed in.'
        );
    }
}
