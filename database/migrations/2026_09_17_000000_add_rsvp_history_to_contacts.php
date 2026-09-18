<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Backs the "what parties has this person come to" view on a contact.
 * Populated by the events:import-rsvps-as-contacts command — a JSON list of
 * {eventId, eventName, eventDate, eventType, checkedIn}, one entry per
 * distinct event the contact has RSVPed to or attended. Not a relation
 * table because event data itself lives on the website (Mongo), not here —
 * this is just a denormalized read cache on the contact.
 */
class AddRsvpHistoryToContacts extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'rsvp_history')) {
                $table->text('rsvp_history')->nullable()->after('import_external_id');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'rsvp_history')) {
                $table->dropColumn('rsvp_history');
            }
        });
    }
}
