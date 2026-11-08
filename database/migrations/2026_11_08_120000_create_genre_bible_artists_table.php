<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artists staff add (or re-genre) on the Genre Bible page. They extend the
 * curated artist sheet (app/Services/data/artist_genres.json) and win over it.
 */
class CreateGenreBibleArtistsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('genre_bible_artists')) {
            return;
        }
        Schema::create('genre_bible_artists', function (Blueprint $table) {
            $table->increments('id');
            $table->string('artist_key', 191)->unique(); // ArtistGenreLookup::normalizeArtist()
            $table->string('name', 191);
            $table->string('genre', 64);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('genre_bible_artists');
    }
}
