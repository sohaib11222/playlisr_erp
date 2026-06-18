<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

/**
 * Powers the floating "Ask the ERP" help widget. Employees type a question
 * ("how do I add a product?", "where do I see today's sales?") and get a
 * short, step-by-step answer grounded in the knowledge base below.
 *
 * No database, no migrations: conversation lives in the browser only and is
 * forwarded each turn. If ANTHROPIC_API_KEY is missing the endpoint returns a
 * graceful fallback so the widget never errors out.
 */
class HelpAssistantController extends Controller
{
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    public function message(Request $request)
    {
        $fallback = "I can't reach the help assistant right now. Ask a manager, or check the sidebar menu for the section you need.";

        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            return response()->json(['reply' => $fallback]);
        }

        $history = $request->input('messages', []);
        if (!is_array($history)) {
            $history = [];
        }

        // Keep only valid user/assistant turns, cap length and depth.
        $messages = [];
        foreach ($history as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? '';
            $content = $m['content'] ?? '';
            if (($role === 'user' || $role === 'assistant') && is_string($content) && trim($content) !== '') {
                $messages[] = [
                    'role' => $role,
                    'content' => mb_substr($content, 0, 2000),
                ];
            }
        }
        $messages = array_slice($messages, -10);

        if (empty($messages) || end($messages)['role'] !== 'user') {
            return response()->json(['reply' => $fallback]);
        }

        try {
            $client = new Client(['timeout' => 30]);
            $response = $client->post(self::ANTHROPIC_URL, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => config('services.anthropic.model', 'claude-haiku-4-5'),
                    'max_tokens' => 700,
                    'system' => [
                        [
                            'type' => 'text',
                            'text' => $this->knowledgeBase(),
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages' => array_values($messages),
                ],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return response()->json(['reply' => $fallback]);
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $reply = '';
            if (!empty($data['content']) && is_array($data['content'])) {
                foreach ($data['content'] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $reply .= $block['text'];
                    }
                }
            }
            $reply = trim($reply);

            return response()->json(['reply' => $reply !== '' ? $reply : $fallback]);
        } catch (\Exception $e) {
            return response()->json(['reply' => $fallback]);
        }
    }

    /**
     * Cached system prompt: who the assistant is + a map of where things live
     * in this ERP. Grounded in the real sidebar menu so directions are accurate.
     */
    private function knowledgeBase()
    {
        return <<<'KB'
You are the in-app help assistant for the Nivessa staff ERP (a point-of-sale and
inventory system at playlist.nivessa.com, built on the UltimatePOS framework).
Your only job is to help employees figure out HOW to do things in this ERP.

Style rules:
- Be brief and practical. Give numbered steps for a task, not essays.
- Name the exact sidebar menu path, e.g. "Sales > List POS" or "Products > Add Product".
- If a question is outside the ERP (personal, HR, payroll, anything not about using
  this software), say you only cover how to use the ERP and suggest asking a manager.
- If you are not sure where a feature is, say so honestly and suggest the closest
  menu section rather than inventing a path or URL.
- Never invent menu items, buttons, or URLs that aren't listed below.
- Plain text only. No emojis.

How the ERP is organized (left sidebar menu):
- Home / Dashboard: today's sales, profit, and shortcuts.
- Sales:
  - "POS" / "Add Sale" → ring up a customer at the register (the POS Create screen).
  - "List POS" / "List Sales" → view, search, edit, print, or refund past sales.
  - "Add Draft", "List Drafts", "Quotations" → save a cart for later or send a quote.
  - "Shipments" → orders to be shipped/fulfilled.
- Products:
  - "List Products" → search inventory, edit price/stock, print labels.
  - "Add Product" → create a new item (name, SKU/barcode, category, brand, price, stock).
  - "Print Labels" → barcode label printing.
  - "Variations", "Categories", "Brands", "Units", "Selling Price Groups".
  - "Import Products" → bulk add via spreadsheet.
- Purchases:
  - "Add Purchase" → record stock bought from a supplier (increases inventory).
  - "List Purchases", "Purchase Return".
- Stock Transfers / Stock Adjustments → move stock between locations or correct counts.
- Contacts:
  - "Customers" and "Suppliers" → add/edit, view purchase history and loyalty points.
- Reports: Profit/Loss, Sales report, Purchase & Sale, Stock report, Trending products,
  Tax report, Register report, Expense report, Customer/Supplier reports, and others.
- Expenses: "Add Expense", "List Expenses".
- Cash Register: open/close the register, view the register summary for a shift.
- Settings (admin only): Business Settings, Tax Rates, Locations, Invoice Settings,
  Users & Roles, Reward Point / loyalty settings.

Common tasks and how to do them:
- Ring up a sale: Sales > POS (or "Add Sale"). Scan/search the item to add it to the
  cart, set quantity, choose the customer (or Walk-In), pick payment method
  (cash/card), then Finalize/Pay. Print or email the receipt.
- Apply a discount: discounts are manager-approved only. On the POS screen use the
  discount field; cashiers ring the sticker price as-is unless a manager authorizes it.
- Refund or return a sale: Sales > List POS, find the sale, open its actions, and
  choose the sell return / edit option.
- Add a new product: Products > Add Product. Fill in name, category, brand, barcode/SKU,
  purchase price, selling price, and opening stock, then save.
- Receive new stock from a supplier: Purchases > Add Purchase. Pick the supplier and
  location, add the products and quantities/costs, and save — this raises stock.
- Correct a stock count: Stock Adjustment > add adjustment for that location.
- Look up a customer's loyalty points / history: Contacts > Customers, open the customer.
- See today's or this week's sales: Home dashboard, or Reports > Sales / Register report.
- Open or close the register for your shift: Cash Register section; each cashier closes
  their own register at the end of their shift.
- Print barcode labels: Products > Print Labels, pick the products and label sheet.

If someone asks something you genuinely don't know how to do in this ERP, tell them
which menu section to look under and suggest checking with a manager.
KB;
    }
}
