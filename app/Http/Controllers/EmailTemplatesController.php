<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Customer email copy editor, moved into the ERP (Sarah, 2026-08-27) so it's
 * the one place admin tooling lives — replaces client-main's
 * /admin/email-templates. Talks to the website's ERP bridge
 * (GET/PUT/POST /api/v1/erp/email-templates*), reusing the exact same
 * controller functions the website admin editor called
 * (server/controllers/emailTemplate.controller.js) — only the auth
 * middleware changed (erpKey instead of verifyAdmin), so saved templates
 * behave identically either way. Bridge-call helpers are the same
 * self-contained pattern as WebsiteOrdersController, not shared with
 * EventsController.
 */
class EmailTemplatesController extends Controller
{
    // Field type controls how the input renders. Keep in sync with
    // FIELD_LABELS / TEMPLATE_META in client-main's (now retired)
    // src/app/admin/email-templates/page.jsx.
    const FIELD_LABELS = [
        'subject'                  => 'Subject Line',
        'headline'                 => 'Main Headline',
        'subheadline'              => 'Sub-headline',
        'intro'                    => 'Opening Message',
        'whatNextTitle'            => '"What\'s Next" Section Title',
        'whatNextItems'            => '"What\'s Next" Bullet Points (one per line)',
        'ctaText'                  => 'Button Text',
        'closingNote'              => 'Closing / Footer Note',
        'statusMessages'           => 'Per-Status Messages',
        'extra.couponIntro'        => 'Coupon Box Title',
        'extra.closingLine'        => 'Sign-off Line',
        'extra.shareBoxTitle'      => 'Share-Box Title',
    ];

    const TEXTAREA_FIELDS = ['intro', 'closingNote'];
    const STATUS_KEYS = ['processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    const TEMPLATE_META = [
        'order_confirmation' => [
            'label' => 'Order Confirmation',
            'description' => 'Sent after a customer successfully pays for an order.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'subheadline', 'whatNextTitle', 'ctaText', 'closingNote'],
        ],
        'shipping_confirmation' => [
            'label' => 'Shipping Confirmation',
            'description' => 'Sent when a tracking number is added to an order.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'subheadline', 'intro', 'ctaText', 'closingNote'],
        ],
        'order_status_update' => [
            'label' => 'Order Status Update',
            'description' => 'Sent when order status changes (processing / cancelled / refunded).',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'ctaText', 'closingNote', 'statusMessages'],
        ],
        'order_delivered' => [
            'label' => 'Order Delivered',
            'description' => "Sent when the order status is set to 'delivered'.",
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'subheadline', 'ctaText'],
        ],
        'free_order_confirmation' => [
            'label' => 'Free / Gift Card Order',
            'description' => 'Sent when an order is fully covered by a gift card.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'subheadline', 'intro', 'ctaText', 'closingNote'],
        ],
        'pickup_ready' => [
            'label' => 'Pickup Ready',
            'description' => 'Sent when an in-store pickup order is marked ready.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'closingNote', 'ctaText'],
        ],
        'gift_card_delivery' => [
            'label' => 'Gift Card Delivery',
            'description' => 'Sent to the recipient when a gift card is purchased.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'ctaText', 'closingNote'],
        ],
        'order_cancellation' => [
            'label' => 'Order Cancellation (apology)',
            'description' => 'Sent from the ERP cancel screen — apology, reason, refund note, coupon.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'intro', 'extra.couponIntro', 'extra.closingLine'],
        ],
        'welcome_signup' => [
            'label' => 'Welcome (new account)',
            'description' => 'Sent right after someone creates a Nivessa account.',
            'group' => 'Account',
            'fields' => ['subject', 'headline', 'intro', 'whatNextItems', 'ctaText', 'closingNote'],
        ],
        'welcome_newsletter' => [
            'label' => 'Welcome (newsletter signup)',
            'description' => 'Sent when someone subscribes to the newsletter (not a full account).',
            'group' => 'Account',
            'fields' => ['subject', 'headline', 'intro', 'closingNote'],
        ],
        'reset_password' => [
            'label' => 'Reset Password',
            'description' => 'Sent when a customer requests a password reset.',
            'group' => 'Account',
            'fields' => ['subject', 'headline', 'intro', 'ctaText', 'closingNote'],
        ],
        'rsvp_confirmation' => [
            'label' => 'RSVP Confirmation',
            'description' => 'Sent to a guest right after they RSVP to an event.',
            'group' => 'Events & Preorders',
            'fields' => ['subject', 'headline', 'extra.shareBoxTitle', 'closingNote'],
        ],
        'preorder_ready' => [
            'label' => 'Preorder Ready for Pickup',
            'description' => 'Sent when a listening-party preorder is ready.',
            'group' => 'Events & Preorders',
            'fields' => ['subject', 'headline', 'intro', 'closingNote'],
        ],
        'booking_confirmation' => [
            'label' => 'Booking Confirmation (detailed)',
            'description' => 'The full show-booking confirmation with venue rules and deposit info.',
            'group' => 'Bookings (venue rentals)',
            'fields' => ['subject', 'headline', 'intro'],
        ],
        'booking_confirmed' => [
            'label' => 'Booking Confirmed (admin-approved)',
            'description' => 'Sent when staff manually confirms a pending booking.',
            'group' => 'Bookings (venue rentals)',
            'fields' => ['subject', 'headline', 'intro', 'whatNextTitle', 'whatNextItems', 'closingNote'],
        ],
        'booking_cancelled' => [
            'label' => 'Booking Cancelled',
            'description' => 'Sent when a venue booking is cancelled.',
            'group' => 'Bookings (venue rentals)',
            'fields' => ['subject', 'headline', 'intro', 'closingNote'],
        ],
        'booking_reminder' => [
            'label' => 'Booking Reminder',
            'description' => 'Sent the day before a scheduled booking.',
            'group' => 'Bookings (venue rentals)',
            'fields' => ['subject', 'headline', 'intro', 'closingNote'],
        ],
        'pos_receipt' => [
            'label' => 'POS Itemized Receipt',
            'description' => 'Sent from POS Create when a cashier emails a customer their receipt.',
            'group' => 'Order Lifecycle',
            'fields' => ['subject', 'headline', 'intro', 'ctaText', 'closingNote'],
        ],
    ];

    const GROUP_ORDER = ['Order Lifecycle', 'Account', 'Events & Preorders', 'Bookings (venue rentals)'];

    public function index()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $resp = $this->websiteApi('GET', '/erp/email-templates');
        $bridgeError = $resp === null || ($resp['success'] ?? true) === false;
        $bridgeErrorMessage = $bridgeError ? ($resp['message'] ?? 'Could not reach the website.') : null;

        $byKey = [];
        foreach (($bridgeError ? [] : ($resp['data'] ?? [])) as $t) {
            $byKey[$t['key']] = $t;
        }

        return view('email-templates.index', [
            'bridgeError' => $bridgeError,
            'bridgeErrorMessage' => $bridgeErrorMessage,
            'templates' => $byKey,
            'meta' => self::TEMPLATE_META,
            'groupOrder' => self::GROUP_ORDER,
            'fieldLabels' => self::FIELD_LABELS,
            'textareaFields' => self::TEXTAREA_FIELDS,
            'statusKeys' => self::STATUS_KEYS,
        ]);
    }

    public function update(Request $request, string $key)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        if (!array_key_exists($key, self::TEMPLATE_META)) {
            abort(404);
        }

        $bodyFields = $this->mergedBodyFields($key, $request);
        $subject = trim((string) $request->input('subject', ''));
        $editorName = trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username;

        $resp = $this->websiteApi('PUT', "/erp/email-templates/{$key}", [
            'subject' => $subject,
            'bodyFields' => $bodyFields,
            'editorName' => $editorName,
        ]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->route('email-templates.index')->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to save this template.');
        }

        return redirect()->route('email-templates.index')->with('status', self::TEMPLATE_META[$key]['label'] . ' saved.');
    }

    public function reset(string $key)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        if (!array_key_exists($key, self::TEMPLATE_META)) {
            abort(404);
        }

        $resp = $this->websiteApi('POST', "/erp/email-templates/{$key}/reset");

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->route('email-templates.index')->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to reset this template.');
        }

        return redirect()->route('email-templates.index')->with('status', self::TEMPLATE_META[$key]['label'] . ' reset to default.');
    }

    /** AJAX — live preview of unsaved form state. Returns {success, data:{subject, html}}. */
    public function preview(Request $request, string $key)
    {
        if (!auth()->user()->can('product.create')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        if (!array_key_exists($key, self::TEMPLATE_META)) {
            return response()->json(['success' => false, 'message' => 'Unknown template'], 404);
        }

        $bodyFields = $this->mergedBodyFields($key, $request);
        $resp = $this->websiteApi('POST', "/erp/email-templates/{$key}/preview", ['bodyFields' => $bodyFields]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return response()->json(['success' => false, 'message' => ($resp['message'] ?? null) ?: 'Could not reach the website.'], 502);
        }

        return response()->json($resp);
    }

    /** AJAX — sends a real test email using whatever's currently SAVED. */
    public function sendTest(Request $request, string $key)
    {
        if (!auth()->user()->can('product.create')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        if (!array_key_exists($key, self::TEMPLATE_META)) {
            return response()->json(['success' => false, 'message' => 'Unknown template'], 404);
        }
        $toEmail = trim((string) $request->input('toEmail', ''));
        if ($toEmail === '') {
            return response()->json(['success' => false, 'message' => 'Enter an email address.'], 422);
        }

        $resp = $this->websiteApi('POST', "/erp/email-templates/{$key}/test", ['toEmail' => $toEmail]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return response()->json(['success' => false, 'message' => ($resp['message'] ?? null) ?: 'Could not reach the website.'], 502);
        }

        return response()->json($resp);
    }

    /**
     * Builds a full bodyFields object for {$key} by starting from whatever is
     * currently saved (or the default if nothing's saved), then overlaying
     * only the fields this request actually posted — so saving/previewing a
     * template never silently wipes fields that exist in the data but aren't
     * exposed in this template's rendered form (e.g. a stray `extra.*` key).
     */
    protected function mergedBodyFields(string $key, Request $request): array
    {
        $current = $this->websiteApi('GET', "/erp/email-templates/{$key}");
        $bodyFields = ($current['success'] ?? false) ? (($current['data']['bodyFields'] ?? []) ?: []) : [];

        $fields = self::TEMPLATE_META[$key]['fields'] ?? [];

        foreach ($fields as $field) {
            if ($field === 'subject' || $field === 'statusMessages') continue;

            if (strpos($field, 'extra.') === 0) {
                $subKey = substr($field, 6);
                if ($request->has("extra.{$subKey}")) {
                    if (!isset($bodyFields['extra']) || !is_array($bodyFields['extra'])) {
                        $bodyFields['extra'] = [];
                    }
                    $bodyFields['extra'][$subKey] = trim((string) $request->input("extra.{$subKey}"));
                }
                continue;
            }

            if ($field === 'whatNextItems') {
                if ($request->has('whatNextItems')) {
                    $lines = preg_split('/\r\n|\r|\n/', (string) $request->input('whatNextItems'));
                    $bodyFields['whatNextItems'] = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
                }
                continue;
            }

            if ($request->has($field)) {
                $bodyFields[$field] = trim((string) $request->input($field));
            }
        }

        if (in_array('statusMessages', $fields, true) && $request->has('statusMessages')) {
            $posted = (array) $request->input('statusMessages');
            $existing = (array) ($bodyFields['statusMessages'] ?? []);
            foreach (self::STATUS_KEYS as $statusKey) {
                if (!isset($posted[$statusKey])) continue;
                $existing[$statusKey] = [
                    'icon' => trim((string) ($posted[$statusKey]['icon'] ?? ($existing[$statusKey]['icon'] ?? ''))),
                    'headline' => trim((string) ($posted[$statusKey]['headline'] ?? ($existing[$statusKey]['headline'] ?? ''))),
                    'message' => trim((string) ($posted[$statusKey]['message'] ?? ($existing[$statusKey]['message'] ?? ''))),
                ];
            }
            $bodyFields['statusMessages'] = $existing;
        }

        return $bodyFields;
    }

    /** Same resolution as WebsiteOrdersController's / EventsController's. */
    protected function erpApiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = $this->envFromDisk('ERP_API_KEY');
        }
        if ($key === '') {
            $key = $this->keyFromStore();
        }
        return $key;
    }

    protected function keyFromStore(): string
    {
        try {
            $path = storage_path('app/events-bridge.json');
            if (!is_file($path)) {
                return '';
            }
            $j = json_decode((string) file_get_contents($path), true);
            return is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function envFromDisk(string $name): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) {
                return '';
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), $name . '=') === 0) {
                    return trim(trim(substr(ltrim($line), strlen($name) + 1)), "\"'");
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    protected function bridgeBaseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }

    /** POST/GET/PUT the website bridge with the shared key. Null on failure/unconfigured. */
    protected function websiteApi(string $method, string $path, array $body = null): ?array
    {
        $key = $this->erpApiKey();
        if ($key === '') {
            return null;
        }
        try {
            $ch = curl_init($this->bridgeBaseUrl() . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
