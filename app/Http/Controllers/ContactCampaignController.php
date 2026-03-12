<?php

namespace App\Http\Controllers;

use App\Business;
use App\Contact;
use App\Notifications\CustomerNotification;
use App\Utils\NotificationUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ContactCampaignController extends Controller
{
    protected $notificationUtil;

    public function __construct(NotificationUtil $notificationUtil)
    {
        $this->notificationUtil = $notificationUtil;
    }

    public function index(Request $request)
    {
        if (!auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $contacts = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNotNull('favorite_genres')
            ->get(['favorite_genres']);

        $genres = [];
        foreach ($contacts as $contact) {
            if (!empty($contact->favorite_genres) && is_array($contact->favorite_genres)) {
                foreach ($contact->favorite_genres as $genre) {
                    $genre = trim($genre);
                    if (!empty($genre)) {
                        $genres[$genre] = $genre;
                    }
                }
            }
        }
        ksort($genres);

        return view('contact.campaign')->with(compact('genres'));
    }

    public function send(Request $request)
    {
        if (!auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'subject' => 'required|string|max:191',
            'message' => 'required|string',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $business = Business::findOrFail($business_id);

        $channel_email = !empty($request->input('channel_email'));
        $channel_sms = !empty($request->input('channel_sms'));
        $only_opted_in = !empty($request->input('only_opted_in'));
        $selected_genre = trim((string) $request->input('genre', ''));

        if (!$channel_email && !$channel_sms) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => 'Please select at least one channel (Email or SMS).'
            ]);
        }

        $contacts_query = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->active();

        if ($only_opted_in) {
            $contacts_query->where('opt_in_marketing', 1);
        }

        $contacts = $contacts_query->get();

        if (!empty($selected_genre)) {
            $contacts = $contacts->filter(function ($contact) use ($selected_genre) {
                if (empty($contact->favorite_genres) || !is_array($contact->favorite_genres)) {
                    return false;
                }

                foreach ($contact->favorite_genres as $genre) {
                    if (strcasecmp(trim($genre), $selected_genre) === 0) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        $sent_email = 0;
        $sent_sms = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            $email_sent = false;
            $sms_sent = false;

            if ($channel_email && !empty($contact->email)) {
                try {
                    $notification_data = [
                        'email_settings' => $business->email_settings,
                        'subject' => $request->input('subject'),
                        'email_body' => nl2br(e($request->input('message'))),
                    ];

                    Notification::route('mail', $contact->email)
                        ->notify(new CustomerNotification($notification_data));
                    $sent_email++;
                    $email_sent = true;
                } catch (\Exception $e) {
                    Log::error('Campaign email failed for contact ' . $contact->id . ': ' . $e->getMessage());
                }
            }

            if ($channel_sms && !empty($contact->mobile)) {
                try {
                    $this->notificationUtil->sendSms([
                        'sms_settings' => $business->sms_settings,
                        'mobile_number' => $contact->mobile,
                        'sms_body' => $request->input('message'),
                    ]);
                    $sent_sms++;
                    $sms_sent = true;
                } catch (\Exception $e) {
                    Log::error('Campaign sms failed for contact ' . $contact->id . ': ' . $e->getMessage());
                }
            }

            if (($channel_email && !$email_sent && !empty($contact->email)) || ($channel_sms && !$sms_sent && !empty($contact->mobile))) {
                $failed++;
            }
        }

        $msg = "Campaign sent. Emails: {$sent_email}, SMS: {$sent_sms}, Failed: {$failed}.";
        return redirect()->back()->with('status', [
            'success' => 1,
            'msg' => $msg
        ]);
    }
}

