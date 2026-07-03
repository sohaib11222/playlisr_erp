<?php

namespace App\Http\Controllers;

/**
 * Phone Playbook — the front-of-house guide for answering calls, triaging them,
 * locating a record across both stores (Pico / Hollywood), and reaching a
 * coworker (Slack first, cell if no answer, on-shift only).
 *
 * Written for Fatteen but useful to anyone on the floor, so it's open to any
 * signed-in staff member (the route group already requires auth). It's a
 * read-only reference — no storage, no logging. Styled to match /pos/create.
 */
class PhonePlaybookController extends Controller
{
    public function index()
    {
        return view('phone_playbook.index');
    }
}
