<?php

namespace App\Services\Reorder;

use App\Services\SupplierFetchers\AmsFetcher;

/**
 * Reads our AMS "My Web Orders" with the same login the nightly price pull
 * uses, so a placed AMS order can be marked as ordered on the Weekly Reorder
 * page without anyone clicking the button (Sarah 10/13).
 */
class AmsOrderWatcher extends AmsFetcher
{
    public function __construct()
    {
        parent::__construct();
        // Own cookie file so this never collides with the price pull.
        $this->cookieJar = storage_path('app/supplier-fetch-cookies-ams-orders.txt');
    }

    protected function signIn(): void
    {
        $creds = $this->readCredentials();
        $this->get('https://www.allmediasupply.com/Account/LogOn');
        $this->login('https://www.allmediasupply.com/Account/LogOn', [
            'AccountNumber' => $creds['AMS_PORTAL_ACCOUNT'],
            'UserName' => $creds['AMS_PORTAL_USER'],
            'Password' => $creds['AMS_PORTAL_PASS'],
            'RememberMe' => 'false',
            'ReturnUrl' => '',
        ]);
        $home = $this->get('https://www.allmediasupply.com/');
        if (stripos($home, 'Sign out') === false && stripos($home, 'logout') === false) {
            throw new \RuntimeException('AMS order watcher: login failed');
        }
    }

    protected function postGrid(string $url, string $extra = ''): array
    {
        $raw = $this->request('POST', $url, [
            'body' => 'sort=&page=1&pageSize=500&group=&filter=' . $extra,
            'headers' => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest'],
        ]);
        $j = json_decode($raw, true);
        return is_array($j) && isset($j['Data']) && is_array($j['Data']) ? $j['Data'] : [];
    }

    /**
     * Orders checked out in the last $days days, each with its lines.
     * @return array<int, array{id:int, account:string, ref:string, at:string, lines:array<int,array{ean:string,qty:int,title:string,artist:string}>}>
     */
    public function recentOrders(int $days = 14): array
    {
        $this->signIn();
        $cut = now()->subDays($days)->getTimestamp() * 1000;
        $out = [];
        foreach ($this->postGrid('https://www.allmediasupply.com/Account/OrderEnquiry_Read') as $o) {
            if (!preg_match('/\((\d+)\)/', (string) ($o['DateCheckedOut'] ?? ''), $m) || (int) $m[1] < $cut) continue;
            $lines = [];
            foreach ($this->postGrid('https://www.allmediasupply.com/Account/OrderEnquiryOrderDetails_Read', '&OrderId=' . (int) $o['OrderId']) as $l) {
                $lines[] = [
                    'ean' => (string) ($l['EAN'] ?? ''),
                    'qty' => (int) ($l['Quantity'] ?? 0),
                    'title' => (string) ($l['Title'] ?? ''),
                    'artist' => (string) ($l['Contributor'] ?? ''),
                ];
            }
            $out[] = [
                'id' => (int) $o['OrderId'],
                'account' => (string) ($o['AccountNumber'] ?? ''),
                'ref' => (string) ($o['CustomerOrderReference'] ?? ''),
                'at' => \Carbon\Carbon::createFromTimestamp(intdiv((int) $m[1], 1000))->setTimezone('America/Los_Angeles')->toDateTimeString(),
                'lines' => $lines,
            ];
        }
        return $out;
    }
}
