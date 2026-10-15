<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com site settings (header, footer with store hours, Ask Nivessa
 * chat bot knowledge), moved from /admin/site-settings (Sarah, 2026-10-15).
 * Admin only. Saves merge into the stored content so fields this page
 * doesn't show (logo, anything added later) are kept.
 */
class SiteSettingsController extends Controller
{
    use WaContentHelpers;

    const TYPES = ['header', 'footer', 'assistant'];

    public function index(Request $request)
    {
        $this->guardAdmin();
        $data = [];
        $errors = [];
        foreach (self::TYPES as $t) {
            $r = WebsiteBridge::admin('GET', '/site-settings/' . $t);
            $content = $this->pick($r, 'data', []);
            $data[$t] = is_array($content) && is_array($content['content'] ?? null) ? $content['content'] : null;
            if (empty($r['success']) && ($r['_http'] ?? 0) !== 404) {
                $errors[] = ucfirst($t) . ': ' . ($r['message'] ?? 'could not load');
            }
        }
        $tab = in_array($request->input('tab'), self::TYPES, true) ? $request->input('tab') : 'header';
        return view('website_admin.content.site_settings', [
            'header' => array_replace(self::defaultHeader(), $data['header'] ?: []),
            'headerSaved' => $data['header'] !== null,
            'footer' => array_replace(self::defaultFooter(), $data['footer'] ?: []),
            'footerSaved' => $data['footer'] !== null,
            'knowledgeBase' => $data['assistant']['knowledgeBase'] ?? self::defaultKnowledge(),
            'assistantSaved' => !empty($data['assistant']['knowledgeBase']),
            'loadErrors' => $errors,
            'tab' => $tab,
        ]);
    }

    public function save(Request $request, $type)
    {
        $this->guardAdmin();
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }
        $current = WebsiteBridge::admin('GET', '/site-settings/' . $type);
        $stored = $this->pick($current, 'data', []);
        $stored = is_array($stored) && is_array($stored['content'] ?? null) ? $stored['content'] : [];

        if ($type === 'header') {
            $content = array_replace(self::defaultHeader(), $stored, [
                'companyName' => (string) $request->input('companyName', ''),
                'phone' => (string) $request->input('phone', ''),
                'shippingInfo' => array_replace($stored['shippingInfo'] ?? [], [
                    'enabled' => $request->has('shippingEnabled'),
                    'text' => (string) $request->input('shippingText', ''),
                    'icon' => (string) $request->input('shippingIcon', ''),
                ]),
                'navLinks' => $this->navLinks($request->input('navLinks', [])),
                'socialLinks' => $this->social($request, $stored['socialLinks'] ?? []),
            ]);
        } elseif ($type === 'footer') {
            $content = array_replace(self::defaultFooter(), $stored, [
                'companyName' => (string) $request->input('companyName', ''),
                'stayConnected' => [
                    'title' => (string) $request->input('stayConnectedTitle', ''),
                    'description' => (string) $request->input('stayConnectedDescription', ''),
                ],
                'questions' => [
                    'title' => (string) $request->input('questionsTitle', ''),
                    'callText' => (string) $request->input('callText', ''),
                ],
                'phone' => (string) $request->input('phone', ''),
                'mission' => [
                    'title' => (string) $request->input('missionTitle', ''),
                    'text' => (string) $request->input('missionText', ''),
                ],
                'quickLinks' => [
                    'title' => (string) $request->input('quickLinksTitle', ''),
                    'links' => $this->links($request->input('quickLinks', [])),
                ],
                'bottomLinks' => $this->links($request->input('bottomLinks', [])),
                'locations' => $this->locations($request->input('locations', [])),
                'socialLinks' => $this->social($request, $stored['socialLinks'] ?? []),
            ]);
        } else {
            $content = array_replace($stored, ['knowledgeBase' => (string) $request->input('knowledgeBase', '')]);
        }

        $resp = WebsiteBridge::admin('PUT', '/site-settings/' . $type, ['content' => $content]);
        if (empty($resp['success'])) {
            return redirect()->route('wa.content.siteSettings', ['tab' => $type])->withInput()->with('error', $resp['message'] ?? 'Failed to update the ' . $type . ' settings.');
        }
        $label = ['header' => 'Header', 'footer' => 'Footer', 'assistant' => 'Chat bot knowledge'][$type];
        return redirect()->route('wa.content.siteSettings', ['tab' => $type])->with('status', $label . ' settings saved.' . ($type === 'assistant' ? ' Changes go live within about a minute.' : ''));
    }

    protected function links($rows): array
    {
        $out = [];
        foreach ((array) $rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            $href = trim((string) ($r['href'] ?? ''));
            if ($label === '' && $href === '') {
                continue;
            }
            $link = ['label' => $label, 'href' => $href];
            if (!empty($r['external'])) {
                $link['external'] = true;
            }
            $out[] = $link;
        }
        return $out;
    }

    protected function navLinks($rows): array
    {
        $out = [];
        foreach ((array) $rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            $href = trim((string) ($r['href'] ?? ''));
            if ($label === '' && $href === '') {
                continue;
            }
            $link = ['label' => $label, 'href' => $href];
            if (!empty($r['external'])) {
                $link['external'] = true;
            }
            if (!empty($r['hasSubmenu'])) {
                $link['submenu'] = $this->links($r['submenu'] ?? []);
            }
            $out[] = $link;
        }
        return $out;
    }

    protected function locations($rows): array
    {
        $out = [];
        foreach ((array) $rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $hours = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($r['hours'] ?? ''))), 'strlen'));
            $out[] = ['name' => $name, 'address' => trim((string) ($r['address'] ?? '')), 'hours' => $hours];
        }
        return $out;
    }

    protected function social(Request $request, array $stored): array
    {
        return array_replace($stored, [
            'instagram' => (string) $request->input('instagram', ''),
            'tiktok' => (string) $request->input('tiktok', ''),
            'youtube' => (string) $request->input('youtube', ''),
        ]);
    }

    /** The website page's built-in defaults, shown until something is saved. */
    public static function defaultHeader(): array
    {
        return [
            'logo' => '',
            'companyName' => 'Nivessa Records',
            'phone' => '213-676-2645',
            'shippingInfo' => ['enabled' => true, 'text' => 'FREE Shipping on orders $39+', 'icon' => "\u{1F69A}"],
            'navLinks' => [
                ['label' => 'Shop Music', 'href' => '/products?productType=Vinyl Records,CDs,Cassettes', 'submenu' => [
                    ['label' => 'All Music', 'href' => '/products?productType=Vinyl Records,CDs,Cassettes'],
                    ['label' => 'Gift Cards', 'href' => '/gift-card'],
                    ['label' => 'Explore Nivessa on Discogs', 'href' => 'https://www.discogs.com/seller/Nivessa', 'external' => true],
                    ['label' => 'Explore Nivessa on eBay', 'href' => 'https://www.ebay.com/usr/nivessarecords', 'external' => true],
                    ['label' => 'Record Store Directory', 'href' => '/record-stores'],
                    ['label' => 'Collectibles Store Directories', 'href' => '/collectibles-store-directories'],
                ]],
                ['label' => 'Shop Movies', 'href' => '/products?productType=VHS+Tapes%2CDVDs+%26+Blu-ray+Discs&sort=Recently+Added&page=1'],
                ['label' => 'Sell Your Stuff', 'href' => '/buy-sell-trade', 'submenu' => [
                    ['label' => 'Buy / Sell / Trade', 'href' => '/buy-sell-trade'],
                    ['label' => "What's My Vinyl Worth?", 'href' => '/vinyl-worth'],
                ]],
                ['label' => 'Events', 'href' => '/events', 'submenu' => [
                    ['label' => 'Upcoming Events', 'href' => '/events-schedule'],
                    ['label' => 'Book Your Event', 'href' => 'https://calendly.com/nivessa', 'external' => true],
                ]],
                ['label' => 'About Us', 'href' => '/about-us'],
                ['label' => 'Contact Us', 'href' => '/contact-us', 'submenu' => [
                    ['label' => 'Need Help?', 'href' => '/contact-us'],
                    ['label' => 'Store Locations & Hours', 'href' => '/locations'],
                ]],
            ],
            'socialLinks' => [
                'instagram' => 'https://www.instagram.com/nivessarecords',
                'tiktok' => 'https://www.tiktok.com/@nivessarecords',
                'youtube' => 'https://www.youtube.com/@niVESSARecords',
            ],
        ];
    }

    public static function defaultFooter(): array
    {
        return [
            'companyName' => 'Nivessa Records',
            'stayConnected' => ['title' => 'STAY CONNECTED', 'description' => 'Sign up to receive updates about events, promos, and the latest news from Nivessa.'],
            'questions' => ['title' => 'QUESTIONS?', 'callText' => 'Call/text any time:'],
            'mission' => ['title' => 'OUR MISSION', 'text' => "Nivessa's mission is to connect people with their favorite music and invoke a sense of nostalgia. We unite the local community through music, events, and camaraderie."],
            'quickLinks' => ['title' => 'QUICK LINKS', 'links' => [
                ['label' => 'FAQs', 'href' => '/faqs'], ['label' => 'Get in Touch', 'href' => '/get-in-touch'],
                ['label' => 'About Us', 'href' => '/about-us'], ['label' => 'Nivessa Values', 'href' => '/nivessa-values'],
                ['label' => 'Free Junk Removal', 'href' => '/free-junk-removal'], ['label' => 'Order Tracking', 'href' => '/tracking'],
                ['label' => 'Press', 'href' => '/news'], ['label' => 'Nivessa Connect', 'href' => '/connect'],
                ['label' => 'Nivessa Select Membership', 'href' => '/nivessa-select'], ['label' => 'Ways to Collaborate', 'href' => '/ways-to-collaborate'],
                ['label' => 'Careers', 'href' => '/careers'],
            ]],
            'phone' => '213-676-2645',
            'locations' => [
                ['name' => 'Nivessa on Pico', 'address' => '5770 W Pico Blvd Los Angeles, CA 90019', 'hours' => ['Mon-Tue 11:00 am - 7:00 pm', 'Wed 11:00 am - 6:00 pm', 'Thu-Fri 11:00 am - 8:00 pm', 'Sat-Sun 10:00 am - 8:00 pm']],
                ['name' => 'Nivessa Hollywood', 'address' => '6434 Hollywood Blvd Los Angeles, CA 90028', 'hours' => ['Sun 9:00 am - 11:00 pm', 'Mon-Thurs 10:00 am - 11:00 pm', 'Fri-Sat 10:00 am - 1:00 am']],
            ],
            'socialLinks' => [
                'instagram' => 'https://www.instagram.com/nivessarecords',
                'tiktok' => 'https://www.tiktok.com/@nivessarecords',
                'youtube' => 'https://www.youtube.com/@niVESSARecords',
            ],
            'bottomLinks' => [
                ['label' => 'Terms', 'href' => '/terms'], ['label' => 'Privacy', 'href' => '/privacy'],
                ['label' => 'Refund Policy', 'href' => '/refund-policy'], ['label' => 'Shipping', 'href' => '/shipping'],
            ],
        ];
    }

    /** The chat bot text bundled with the website, used until one is saved. */
    public static function defaultKnowledge(): string
    {
        $path = resource_path('views/website_admin/content/assistant_knowledge_default.txt');
        return is_file($path) ? "\n" . (string) file_get_contents($path) : '';
    }
}
