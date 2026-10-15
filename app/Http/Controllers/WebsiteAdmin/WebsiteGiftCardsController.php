<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * nivessa.com gift cards in the ERP (client /admin/gift-cards). These are
 * the website's own cards, separate from the ERP's GiftCardController at
 * /gift-cards, hence the /website-gift-cards prefix.
 * (Sarah, 2026-10-15)
 */
class WebsiteGiftCardsController extends CatalogBaseController
{
    const STATUSES = ['active' => 'Active', 'used' => 'Used', 'expired' => 'Expired'];

    public function index(Request $request)
    {
        $this->guard();
        $page = max(1, (int) $request->input('page', 1));
        $limit = 20;
        $resp = $this->bridge('GET', "/gift-card/all?page={$page}&limit={$limit}");
        $total = (int) ($resp['totalCount'] ?? 0);
        return view('website_admin.catalog.giftcards.index', [
            'rows' => is_array($resp['data'] ?? null) ? $resp['data'] : [],
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
            'total' => $total,
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load gift cards.') : null,
        ]);
    }

    public function create()
    {
        $this->guard();
        return view('website_admin.catalog.giftcards.form', ['g' => [], 'isNew' => true, 'statuses' => self::STATUSES]);
    }

    public function store(Request $request)
    {
        $this->guard();
        $body = $this->fields($request, false);
        if ($body === null) {
            return back()->withInput()->with('error', 'Enter a code, an amount and an expiry date.');
        }
        $resp = $this->bridge('POST', '/gift-card/create', $body);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The gift card was not added.'));
        }
        return redirect()->route('wa.catalog.giftcards')->with('status', 'Gift card added.');
    }

    public function edit($id)
    {
        $this->guard();
        $resp = $this->bridge('GET', '/gift-card/single/' . rawurlencode($id));
        if (empty($resp['success']) || empty($resp['data'])) {
            return redirect()->route('wa.catalog.giftcards')->with('error', $this->failMessage($resp, 'That gift card was not found.'));
        }
        return view('website_admin.catalog.giftcards.form', ['g' => $resp['data'], 'isNew' => false, 'statuses' => self::STATUSES]);
    }

    public function update(Request $request, $id)
    {
        $this->guard();
        $body = $this->fields($request, true);
        if ($body === null) {
            return back()->withInput()->with('error', 'Enter a code, an amount and an expiry date.');
        }
        $resp = $this->bridge('PUT', '/gift-card/edit/' . rawurlencode($id), $body);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The gift card was not saved.'));
        }
        return redirect()->route('wa.catalog.giftcards')->with('status', 'Gift card saved.');
    }

    protected function fields(Request $request, bool $withStatus)
    {
        $body = [
            'code' => trim((string) $request->input('code')),
            'amount' => $request->input('amount'),
            'description' => trim((string) $request->input('description', '')),
            'expiryDate' => trim((string) $request->input('expiryDate')),
        ];
        if ($body['code'] === '' || !is_numeric($body['amount']) || $body['expiryDate'] === '') {
            return null;
        }
        $body['amount'] = (float) $body['amount'];
        if ($withStatus) {
            $body['status'] = array_key_exists($request->input('status'), self::STATUSES) ? $request->input('status') : 'active';
        }
        return $body;
    }

    public function destroy($id)
    {
        $this->guard();
        $resp = $this->bridge('DELETE', '/gift-card/delete/' . rawurlencode($id));
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Gift card deleted.' : $this->failMessage($resp, 'The gift card was not deleted.'));
    }

    /** Re-sends the delivery email, to the saved recipient or the address typed in. */
    public function resend(Request $request, $id)
    {
        $this->guard();
        $to = trim((string) $request->input('to', ''));
        if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'That email address does not look right.');
        }
        $resp = $this->bridge('POST', '/gift-card/resend/' . rawurlencode($id), $to !== '' ? ['to' => $to] : [], 30);
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? $this->failMessage($resp, 'Gift card email sent.') : $this->failMessage($resp, 'The gift card email was not sent.'));
    }
}
