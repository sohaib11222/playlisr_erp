<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Venue bookings, calendar, missed-booking backfill and venue CRUD, moved
 * from nivessa.com /admin/bookings, /admin/bookings/calendar,
 * /admin/add-booking and /admin/venues (Sarah, 2026-10-15). Every action
 * calls the website's own handler through WebsiteBridge::admin, so the
 * Stripe charge / deposit hold logic stays in one place (the website).
 */
class WebsiteBookingsController extends Controller
{
    const EVENT_TYPES = ['Concert', 'Live Show', 'Filming', 'Private Party', 'Corporate Event', 'Album Release', 'Showcase', 'Recording', 'Other'];

    const DAYS = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];

    const PRESET_ADDONS = [
        ['name' => 'Sound Control', 'description' => 'Professional acoustic management', 'additionalCost' => 50, 'included' => false],
        ['name' => 'Hired Help (Staff)', 'description' => 'On-site staff assistance', 'additionalCost' => 75, 'included' => false],
        ['name' => 'Full Store Closure', 'description' => 'Private exclusive use of entire store', 'additionalCost' => 150, 'included' => false],
        ['name' => 'Large Crew (4+ People)', 'description' => 'Accommodation for large production crews', 'additionalCost' => 100, 'included' => false],
        ['name' => 'Peak Time (Weekend)', 'description' => 'Weekend & holiday premium rate', 'additionalCost' => 80, 'included' => false],
    ];

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    // ---------------------------------------------------------------- list

    /** Unpaid booking requests, newest first, for Abandoned Carts > Venue bookings. */
    public static function unpaidRequests(&$error = null): array
    {
        $resp = WebsiteBridge::admin('GET', '/bookings?' . http_build_query(['limit' => 1000, 'status' => 'pending']));
        $error = empty($resp['success']) ? ($resp['message'] ?? 'Failed to load bookings') : null;
        $raw = $error ? [] : (array) ($resp['data'] ?? $resp['bookings'] ?? []);
        $self = app(self::class);
        $rows = array_values(array_filter(array_map([$self, 'presentBooking'], array_values(array_filter($raw, 'is_array'))), function ($b) {
            return !empty($b['expired']);
        }));
        usort($rows, function ($a, $b) { return strcmp($b['createdAt'] === '' ? '' : date('Y-m-d H:i', strtotime($b['createdAt'])), $a['createdAt'] === '' ? '' : date('Y-m-d H:i', strtotime($a['createdAt']))); });
        return $rows;
    }

    public function index(Request $request)
    {
        $this->guard();

        $status = (string) $request->query('status', 'all');
        $startDate = (string) $request->query('startDate', '');
        $endDate = (string) $request->query('endDate', '');

        // The website page asked with no limit and only ever saw the 20
        // latest; ask for everything so the tabs count every booking.
        $q = ['limit' => 1000];
        if ($status !== '' && $status !== 'all') {
            $q['status'] = $status;
        }
        if ($startDate !== '') {
            $q['startDate'] = $startDate;
        }
        if ($endDate !== '') {
            $q['endDate'] = $endDate;
        }
        $resp = WebsiteBridge::admin('GET', '/bookings?' . http_build_query($q));
        $error = empty($resp['success']) ? ($resp['message'] ?? 'Failed to load bookings') : null;
        $raw = $error ? [] : (array) ($resp['data'] ?? $resp['bookings'] ?? []);

        $bookings = array_map([$this, 'presentBooking'], array_values(array_filter($raw, 'is_array')));
        $abandonedCount = count(array_filter($bookings, function ($b) { return !empty($b['expired']); }));
        $bookings = array_values(array_filter($bookings, function ($b) { return empty($b['expired']); }));

        $stats = ['total' => count($bookings), 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'revenue' => 0.0];
        foreach ($bookings as $b) {
            if (in_array($b['status'], ['pending', 'confirmed', 'completed'], true) && empty($b['expired'])) {
                $stats[$b['status']]++;
            }
            if ($b['status'] === 'confirmed') {
                $stats['revenue'] += $b['total'];
            }
        }

        return view('website_admin.bookings.index', [
            'bookings'  => $bookings,
            'abandonedCount' => $abandonedCount,
            'error'     => $error,
            'stats'     => $stats,
            'status'    => $status,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);
    }

    public function status(Request $request, $id)
    {
        $this->guard();
        $new = (string) $request->input('status');
        if (!in_array($new, ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'], true)) {
            return back()->with('error', 'Unknown status.');
        }
        $resp = WebsiteBridge::admin('PATCH', '/bookings/' . $id . '/status', ['status' => $new]);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Could not update the booking.');
        }
        return back()->with('status', $new === 'confirmed' ? 'Booking confirmed. The customer was emailed.' : 'Booking marked ' . $new . '.');
    }

    /** Edit date / time / label. Same overnight rule as the website: an end at or before the start is the next day. */
    public function edit(Request $request, $id)
    {
        $this->guard();
        $date = substr((string) $request->input('date'), 0, 10);
        $start = $this->to24h($request->input('startTime'));
        $end = $this->to24h($request->input('endTime'));
        $title = trim((string) $request->input('title'));

        if ($date === '' || $start === '' || $end === '') {
            return response()->json(['success' => false, 'message' => 'Date, start, and end time are all required.']);
        }
        list($sh, $sm) = array_map('intval', explode(':', $start));
        list($eh, $em) = array_map('intval', explode(':', $end));
        $startMin = $sh * 60 + $sm;
        $endMin = $eh * 60 + $em;
        if ($endMin <= $startMin) {
            $endMin += 24 * 60;
        }
        $duration = ($endMin - $startMin) / 60;
        if (!($duration > 0)) {
            return response()->json(['success' => false, 'message' => 'End time must be after start time.']);
        }

        $body = ['booking' => ['date' => $date, 'startTime' => $start, 'endTime' => $end, 'duration' => $duration]];
        if ($title !== '') {
            $body['eventDetails'] = ['title' => $title];
        }
        $resp = WebsiteBridge::admin('PATCH', '/bookings/' . $id, $body);
        if (empty($resp['success'])) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Update failed']);
        }
        session()->flash('status', 'Booking updated.');
        return response()->json(['success' => true]);
    }

    /** Charge card on file (cleaning, damage, missing items). Website: POST /bookings/:id/charge. */
    public function charge(Request $request, $id)
    {
        $this->guard();
        $amount = (float) $request->input('amount');
        $reason = trim((string) $request->input('reason'));
        if (!($amount > 0)) {
            return response()->json(['success' => false, 'message' => 'Enter an amount greater than 0.']);
        }
        if ($reason === '') {
            return response()->json(['success' => false, 'message' => 'Add a short reason (e.g. "Cleaning fee").']);
        }
        $resp = WebsiteBridge::admin('POST', '/bookings/' . $id . '/charge', ['amount' => $amount, 'reason' => $reason], 45);
        if (empty($resp['success'])) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Charge failed']);
        }
        session()->flash('status', 'Charged $' . number_format($amount, 2) . ' to the card on file.');
        return response()->json(['success' => true]);
    }

    /** Deposit hold lifecycle: place / capture / release. */
    public function deposit(Request $request, $id, $action)
    {
        $this->guard();
        $path = [
            'place'   => '/place-deposit-hold',
            'capture' => '/capture-deposit',
            'release' => '/release-deposit',
        ][$action] ?? null;
        if (!$path) {
            return response()->json(['success' => false, 'message' => 'Unknown deposit action.']);
        }
        $resp = WebsiteBridge::admin('POST', '/bookings/' . $id . $path, [], 45);
        if (empty($resp['success'])) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'unknown error']);
        }
        $done = ['place' => 'Deposit hold placed.', 'capture' => 'Deposit captured.', 'release' => 'Deposit released.'][$action];
        session()->flash('status', $done);
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------ calendar

    public function calendar(Request $request)
    {
        $this->guard();
        $tz = new \DateTimeZone('America/Los_Angeles');
        $today = new \DateTime('now', $tz);
        $month = (string) $request->query('month', $today->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = $today->format('Y-m');
        }
        $first = \DateTime::createFromFormat('!Y-m-d', $month . '-01', $tz);
        $daysInMonth = (int) $first->format('t');

        // Booking dates are stored at UTC midnight of the booked day, so ask
        // for the whole month in UTC (the website asked from local midnight
        // and could drop bookings on the 1st).
        $q = [
            'startDate' => $month . '-01T00:00:00.000Z',
            'endDate'   => $month . '-' . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT) . 'T23:59:59.999Z',
        ];
        $resp = WebsiteBridge::admin('GET', '/bookings?' . http_build_query($q));
        $error = empty($resp['success']) ? ($resp['message'] ?? 'Failed to load bookings') : null;
        $raw = $error ? [] : (array) ($resp['data'] ?? []);

        $byDay = [];
        foreach ($raw as $b) {
            if (!is_array($b)) {
                continue;
            }
            $p = $this->presentBooking($b);
            if ($p['dateYmd'] !== '' && substr($p['dateYmd'], 0, 7) === $month) {
                $byDay[(int) substr($p['dateYmd'], 8, 2)][] = $p;
            }
        }

        $day = (int) $request->query('day', 0);
        if ($day < 1 || $day > $daysInMonth) {
            $day = 0;
        }

        $prev = (clone $first)->modify('-1 month')->format('Y-m');
        $next = (clone $first)->modify('+1 month')->format('Y-m');

        return view('website_admin.bookings.calendar', [
            'error'       => $error,
            'month'       => $month,
            'monthLabel'  => $first->format('F Y'),
            'leading'     => (int) $first->format('w'),
            'daysInMonth' => $daysInMonth,
            'byDay'       => $byDay,
            'day'         => $day,
            'dayLabel'    => $day ? \DateTime::createFromFormat('!Y-m-d', $month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT), $tz)->format('l, F j, Y') : null,
            'todayMonth'  => $today->format('Y-m'),
            'todayDay'    => (int) $today->format('j'),
            'prev'        => $prev,
            'next'        => $next,
        ]);
    }

    // ----------------------------------------------------- missed booking

    public function addForm(Request $request)
    {
        $this->guard();
        $venues = $this->fetchVenues($venueError);
        $preferred = null;
        foreach ($venues as $v) {
            if (preg_match('/record store & event stage/i', $v['name'] ?? '')) {
                $preferred = $v['_id'];
                break;
            }
        }
        if (!$preferred) {
            foreach ($venues as $v) {
                if (preg_match('/hollywood/i', $v['location']['store'] ?? '')) {
                    $preferred = $v['_id'];
                    break;
                }
            }
        }
        if (!$preferred && $venues) {
            $preferred = $venues[0]['_id'] ?? null;
        }

        return view('website_admin.bookings.add', [
            'venues'     => $venues,
            'venueError' => $venueError,
            'preferred'  => $preferred,
            'eventTypes' => self::EVENT_TYPES,
        ]);
    }

    public function addStore(Request $request)
    {
        $this->guard();
        $venue = (string) $request->input('venue');
        if ($venue === '') {
            return back()->withInput()->with('error', 'Please pick a venue.');
        }
        $start = $this->to24h($request->input('startTime'));
        $end = $this->to24h($request->input('endTime'));
        $toMin = function ($t) {
            if ($t === '') {
                return 0;
            }
            list($h, $m) = array_map('intval', explode(':', $t));
            return $h * 60 + $m;
        };
        $durationMins = $toMin($end) - $toMin($start);
        $hours = $durationMins > 0 ? round($durationMins / 60, 2) : 1;
        $amount = (float) $request->input('amount', 0);

        $payload = [
            'venue'    => $venue,
            'customer' => [
                'name'    => (string) $request->input('customerName'),
                'email'   => (string) $request->input('customerEmail'),
                'phone'   => trim((string) $request->input('customerPhone')) !== '' ? (string) $request->input('customerPhone') : 'N/A',
                'company' => (string) $request->input('customerCompany'),
            ],
            'eventDetails' => [
                'type'              => (string) $request->input('eventType'),
                'title'             => (string) $request->input('eventTitle'),
                'description'       => (string) $request->input('eventDescription'),
                'expectedAttendees' => max(1, (int) $request->input('expectedAttendees', 1)),
            ],
            'booking' => [
                'date'      => (string) $request->input('date'),
                'startTime' => $start,
                'endTime'   => $end,
                'duration'  => $hours,
            ],
            'pricing' => [
                'baseRate'    => $amount,
                'hours'       => $hours,
                'subtotal'    => $amount,
                'cleaningFee' => 0,
                'tax'         => 0,
                'total'       => $amount,
            ],
            'payment'               => ['method' => 'stripe', 'status' => 'paid', 'paidAmount' => $amount],
            'sendConfirmationEmail' => $request->has('sendConfirmationEmail'),
        ];

        $resp = WebsiteBridge::admin('POST', '/bookings/backfill', $payload, 30);
        if (empty($resp['success'])) {
            $msg = $resp['message'] ?? $resp['error'] ?? 'Failed to create booking.';
            return back()->withInput()->with('error', $msg);
        }
        $num = $resp['data']['bookingNumber'] ?? null;
        $msg = 'Booking created.';
        if ($num) {
            $msg .= ' Booking #' . $num . (!empty($resp['emailed']) ? ', receipt emailed.' : ', record created (no email sent).');
        }
        return redirect()->route('wa.bookings.add')->with('status', $msg);
    }

    // -------------------------------------------------------------- venues

    public function venues(Request $request)
    {
        $this->guard();
        $venues = $this->fetchVenues($error);
        $venues = array_map(function ($v) {
            $v['_img'] = !empty($v['images'][0]) ? $this->imageUrl($v['images'][0]) : null;
            return $v;
        }, $venues);
        return view('website_admin.bookings.venues', ['venues' => $venues, 'error' => $error]);
    }

    public function venueCreate()
    {
        $this->guard();
        return $this->venueFormView(null);
    }

    public function venueEdit($id)
    {
        $this->guard();
        $venue = $this->findVenue($id, $error);
        if (!$venue) {
            return redirect()->route('wa.bookings.venues')->with('error', $error ?: 'Venue not found. Inactive venues are hidden by the website and cannot be opened here.');
        }
        return $this->venueFormView($venue);
    }

    public function venueStore(Request $request)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('POST', '/venues', $this->venuePayload($request, null));
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to add venue');
        }
        return redirect()->route('wa.bookings.venues')->with('status', 'Venue created successfully.');
    }

    public function venueUpdate(Request $request, $id)
    {
        $this->guard();
        $existing = $this->findVenue($id, $err);
        $resp = WebsiteBridge::admin('PUT', '/venues/' . $id, $this->venuePayload($request, $existing));
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to edit venue');
        }
        return redirect()->route('wa.bookings.venues')->with('status', 'Venue updated successfully.');
    }

    public function venueDelete($id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('DELETE', '/venues/' . $id);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to delete venue');
        }
        return back()->with('status', 'Venue deleted successfully.');
    }

    /** One image -> website POST /upload/venue-image (Cloudinary). Returns {success, path, url}. */
    public function venueImageUpload(Request $request)
    {
        $this->guard();
        $file = $request->file('image');
        if (!$file || !$file->isValid()) {
            return response()->json(['success' => false, 'message' => 'No image received.']);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['success' => false, 'message' => $file->getClientOriginalName() . ' is too large. Max size is 10MB.']);
        }
        $resp = WebsiteBridge::adminUpload('POST', '/upload/venue-image', [], ['image' => $file]);
        $path = $resp['data']['path'] ?? null;
        if (empty($resp['success']) || !$path) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Upload failed']);
        }
        return response()->json(['success' => true, 'path' => $path, 'url' => $this->imageUrl($path)]);
    }

    /** Cleans up a stored image the same way the website did (Cloudinary + legacy /imageNiv/venues/ only). */
    public function venueImageDelete(Request $request)
    {
        $this->guard();
        $path = (string) $request->input('path');
        $isCloudinary = strpos($path, 'res.cloudinary.com') !== false;
        $isLegacy = strpos($path, '/imageNiv/venues/') === 0;
        if ($isCloudinary || $isLegacy) {
            $resp = WebsiteBridge::admin('DELETE', '/upload/venue-image', ['path' => $path]);
            return response()->json(['success' => !empty($resp['success']), 'message' => $resp['message'] ?? null]);
        }
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- helpers

    protected function venueFormView($venue)
    {
        $defaultAvail = [];
        foreach (array_keys(self::DAYS) as $d) {
            $defaultAvail[$d] = ['available' => $d !== 'sunday', 'openTime' => '10:00', 'closeTime' => '22:00'];
        }
        $images = $venue ? array_values((array) ($venue['images'] ?? [])) : [];
        return view('website_admin.bookings.venue_form', [
            'venue'        => $venue,
            'mode'         => $venue ? 'edit' : 'add',
            'availability' => $venue && !empty($venue['availability']) ? $venue['availability'] : $defaultAvail,
            'amenities'    => $venue ? array_values((array) ($venue['amenities'] ?? [])) : [],
            'images'       => array_map(function ($p) {
                return ['path' => $p, 'url' => $this->imageUrl($p)];
            }, $images),
            'presets'      => self::PRESET_ADDONS,
            'days'         => self::DAYS,
        ]);
    }

    protected function venuePayload(Request $request, $existing)
    {
        $num = function ($v) {
            $v = trim((string) $v);
            return $v === '' ? null : (float) $v + 0;
        };
        $loc = (array) $request->input('location', []);
        $cap = (array) $request->input('capacity', []);
        $pri = (array) $request->input('pricing', []);

        // Merge onto what the venue already has so fields this form doesn't
        // show (coordinates etc.) survive the PUT, same as the website form.
        $location = array_merge((array) ($existing['location'] ?? []), [
            'address' => (string) ($loc['address'] ?? ''),
            'city'    => (string) ($loc['city'] ?? ''),
            'state'   => (string) ($loc['state'] ?? ''),
            'zipCode' => (string) ($loc['zipCode'] ?? ''),
            'store'   => in_array($loc['store'] ?? '', ['Hollywood', 'Pico'], true) ? $loc['store'] : 'Hollywood',
        ]);
        $capacity = array_merge((array) ($existing['capacity'] ?? []), [
            'seated'      => $num($cap['seated'] ?? ''),
            'standing'    => $num($cap['standing'] ?? ''),
            'maxCapacity' => $num($cap['maxCapacity'] ?? ''),
        ]);
        $pricing = array_merge((array) ($existing['pricing'] ?? []), [
            'hourlyRate'      => $num($pri['hourlyRate'] ?? ''),
            'peakHourlyRate'  => $num($pri['peakHourlyRate'] ?? ''),
            'cleaningFee'     => $num($pri['cleaningFee'] ?? ''),
            'securityDeposit' => $num($pri['securityDeposit'] ?? ''),
        ]);

        $availIn = (array) $request->input('availability', []);
        $availability = [];
        foreach (array_keys(self::DAYS) as $d) {
            $row = (array) ($availIn[$d] ?? []);
            $availability[$d] = [
                'available' => !empty($row['available']),
                'openTime'  => $this->to24h($row['openTime'] ?? '') ?: '10:00',
                'closeTime' => $this->to24h($row['closeTime'] ?? '') ?: '22:00',
            ];
        }

        $amenities = json_decode((string) $request->input('amenities_json', '[]'), true);
        $images = json_decode((string) $request->input('images_json', '[]'), true);

        return [
            'name'         => trim((string) $request->input('name')),
            'description'  => (string) $request->input('description'),
            'location'     => $location,
            'capacity'     => $capacity,
            'pricing'      => $pricing,
            'amenities'    => is_array($amenities) ? array_values($amenities) : [],
            'images'       => is_array($images) ? array_values(array_filter($images, 'is_string')) : [],
            'availability' => $availability,
            'isActive'     => $request->has('isActive'),
        ];
    }

    protected function fetchVenues(&$error = null)
    {
        $resp = WebsiteBridge::admin('GET', '/venues');
        if (empty($resp['success'])) {
            $error = $resp['message'] ?? 'Failed to load venues';
            return [];
        }
        $error = null;
        $list = $resp['data'] ?? [];
        return array_values(array_filter((array) $list, 'is_array'));
    }

    protected function findVenue($id, &$error = null)
    {
        foreach ($this->fetchVenues($error) as $v) {
            if (($v['_id'] ?? null) === $id) {
                return $v;
            }
        }
        return null;
    }

    /** Flattens a website booking doc into what the views need. */
    protected function presentBooking(array $b)
    {
        $venue = is_array($b['venue'] ?? null) ? $b['venue'] : [];
        $bk = (array) ($b['booking'] ?? []);
        $dateYmd = '';
        $dateLabel = '';
        if (!empty($bk['date'])) {
            try {
                // Stored at UTC midnight = the booked calendar day.
                $d = new \DateTime((string) $bk['date'], new \DateTimeZone('UTC'));
                $d->setTimezone(new \DateTimeZone('UTC'));
                $dateYmd = $d->format('Y-m-d');
                $dateLabel = $d->format('n/j/Y');
            } catch (\Throwable $e) {
                $dateYmd = substr((string) $bk['date'], 0, 10);
            }
        }
        $deposit = is_array($b['deposit'] ?? null) ? $b['deposit'] : null;
        return [
            'id'            => (string) ($b['_id'] ?? ''),
            'bookingNumber' => (string) ($b['bookingNumber'] ?? ''),
            'title'         => (string) ($b['eventDetails']['title'] ?? ''),
            'customerName'  => (string) ($b['customer']['name'] ?? ''),
            'customerEmail' => (string) ($b['customer']['email'] ?? ''),
            'venueName'     => (string) ($venue['name'] ?? ''),
            'venueSlug'     => (string) ($venue['slug'] ?? ''),
            'dateYmd'       => $dateYmd,
            'dateLabel'     => $dateLabel,
            'startTime'     => (string) ($bk['startTime'] ?? ''),
            'endTime'       => (string) ($bk['endTime'] ?? ''),
            'start24'       => $this->to24h($bk['startTime'] ?? ''),
            'end24'         => $this->to24h($bk['endTime'] ?? ''),
            'timeLabel'     => $this->fmtTime($bk['startTime'] ?? '') . ' - ' . $this->fmtTime($bk['endTime'] ?? ''),
            'total'         => (float) ($b['pricing']['total'] ?? 0),
            'status'        => (string) ($b['status'] ?? ''),
            'paymentStatus' => (string) ($b['payment']['status'] ?? ''),
            'paymentMethod' => (string) ($b['payment']['method'] ?? ''),
            // A request nobody paid for is an abandoned cart, not a booking:
            // it lives on Abandoned Carts > Venue bookings (Sarah, 2026-10-15).
            'expired'       => ($b['status'] ?? '') === 'pending' && ($b['payment']['status'] ?? '') !== 'paid',
            'customerPhone' => (string) ($b['customer']['phone'] ?? ''),
            'createdAt'     => !empty($b['createdAt']) ? \Carbon\Carbon::parse($b['createdAt'])->setTimezone('America/Los_Angeles')->format('m/d/y g:ia') : '',
            'depositStatus' => $deposit ? (string) ($deposit['status'] ?? 'none') : 'none',
            'depositAmount' => $deposit && isset($deposit['amount']) ? (float) $deposit['amount'] : 150.0,
            'hasDeposit'    => $deposit !== null,
        ];
    }

    /** "4:30 PM" / "16:30" / "16:30:00" -> "16:30"; '' when unparseable. */
    protected function to24h($t)
    {
        $s = trim((string) $t);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})\s*([AaPp])\.?[Mm]\.?$/', $s, $m)) {
            $h = ((int) $m[1]) % 12;
            if (strtolower($m[3]) === 'p') {
                $h += 12;
            }
            return sprintf('%02d:%s', $h, $m[2]);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $s, $m)) {
            return sprintf('%02d:%s', (int) $m[1], $m[2]);
        }
        return '';
    }

    protected function fmtTime($t)
    {
        $s = $this->to24h($t);
        if ($s === '') {
            return trim((string) $t);
        }
        list($h, $m) = explode(':', $s);
        $h = (int) $h;
        return (($h % 12) ?: 12) . ':' . $m . ' ' . ($h >= 12 ? 'PM' : 'AM');
    }

    /** Same mapping as the website's getImageUrl (venue images live on nivessa.com or Cloudinary). */
    public function imageUrl($path)
    {
        $p = (string) $path;
        $site = 'https://nivessa.com';
        if ($p === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }
        if (strpos($p, '/imageNiv/venues/') === 0) {
            return $site . '/venue-img/' . substr($p, strlen('/imageNiv/venues/'));
        }
        if (strpos($p, '/venues/') === 0) {
            return $site . '/venue-img' . substr($p, strlen('/venues'));
        }
        return $site . (strpos($p, '/') === 0 ? '' : '/') . $p;
    }
}
