<?php

namespace App\Services;

/**
 * Look up a curated `Artist -> Genre/Bin` override the store maintains in a
 * spreadsheet (more accurate than Discogs' genres for how records are actually
 * binned). The map is built from the `.numbers` export by
 * scripts/build_artist_genres.py into app/Services/data/artist_genres.json.
 *
 * Used to steer category resolution: when an artist is in the sheet, the bin
 * wins over whatever Discogs reports (see DiscogsReleaseImportMapper) and
 * drives the Mass Add auto-fill.
 */
class ArtistGenreLookup
{
    /** Genre Bible decision for names that aren't really an artist ("Music", "CATS"). */
    const NOT_AN_ARTIST = 'Not an artist';

    /** @var array<string,string>|null normalized artist => bin label */
    private static $map = null;

    /** @var array<string,array{name:string,genre:string}>|null staff-added artists */
    private static $added = null;

    /**
     * Each spreadsheet bin -> ordered ERP category search terms. The store's
     * subcategory naming varies (e.g. "Hip-Hop" vs "Hip Hop", "R&B" vs
     * "Funk/Soul"), so we hand resolution an ordered candidate list; the
     * resolver keeps the earliest match on ties. Terms are lowercase to match
     * resolveCategoryFromGenres()'s comparison.
     *
     * @var array<string,string[]>
     */
    private const BIN_TERMS = [
        'Rock'                 => ['rock'],
        'Hip-Hop / Rap'        => ['hip-hop', 'hip hop', 'rap'],
        'Alternative / Indie'  => ['alt rock', 'alternative', 'indie'],
        'Metal'                => ['metal'],
        'R&B/Funk/Soul'        => ['r&b', 'funk', 'soul'],
        'Pop'                  => ['pop'],
        'Jazz'                 => ['jazz'],
        'Electronic / Dance'   => ['electronic', 'dance'],
        'Country'              => ['country'],
        'Classical'            => ['classical'],
        'Latin'                => ['latin'],
        'Folk'                 => ['folk'],
        'Blues'                => ['blues'],
        'Punk'                 => ['punk'],
        'Reggae'               => ['reggae'],
        'World'                => ['world'],
        'New Wave'             => ['new wave'],
        'Gospel'               => ['gospel'],
        'Soundtrack'           => ['soundtrack', 'soundtracks'],
    ];

    /**
     * Resolve an artist string (from Discogs or typed in Mass Add) to its bin
     * label, or null when not in the sheet. Tries the full normalized string
     * first, then the primary artist of a multi-artist "A, B" string.
     */
    public function binForArtist(?string $artist): ?string
    {
        if ($artist === null || trim($artist) === '') {
            return null;
        }
        $map = $this->map();

        $full = $this->normalizeArtist($artist);
        if ($full !== '' && isset($map[$full])) {
            return $map[$full];
        }

        // Multi-artist releases come through comma-joined ("A, B"); fall back
        // to the primary (first) artist.
        if (mb_strpos($artist, ',') !== false) {
            $primary = $this->normalizeArtist(explode(',', $artist)[0]);
            if ($primary !== '' && $primary !== $full && isset($map[$primary])) {
                return $map[$primary];
            }
        }

        return null;
    }

    /**
     * Ordered ERP category search terms for an artist, or [] when the artist
     * isn't in the sheet (caller falls back to Discogs genres/styles).
     *
     * @return string[]
     */
    public function termsForArtist(?string $artist): array
    {
        $bin = $this->binForArtist($artist);
        if ($bin === null) {
            return [];
        }
        return self::BIN_TERMS[$bin] ?? [];
    }

    /**
     * Normalize an artist name to a lookup key. MUST stay in sync with
     * normalize_artist() in scripts/build_artist_genres.py.
     */
    public function normalizeArtist(string $artist): string
    {
        $s = mb_strtolower(trim($artist));
        $s = preg_replace('/\s*\(\d+\)$/u', '', $s);  // drop Discogs "(2)" suffix
        $s = preg_replace('/\s+/u', ' ', $s);          // collapse whitespace
        return trim($s);
    }

    /**
     * Artists added (or re-genred) by staff on the Genre Bible page:
     * normalized => ['name' => ..., 'genre' => ...]. Empty if the table
     * isn't there yet.
     *
     * @return array<string,array{name:string,genre:string}>
     */
    public function added(): array
    {
        if (self::$added === null) {
            self::$added = [];
            try {
                foreach (\DB::table('genre_bible_artists')->get(['artist_key', 'name', 'genre']) as $r) {
                    self::$added[(string) $r->artist_key] = ['name' => (string) $r->name, 'genre' => (string) $r->genre];
                }
            } catch (\Throwable $e) {
                // Table not migrated yet: sheet only.
            }
        }
        return self::$added;
    }

    /** Forget cached sheet + additions (after staff add an artist). */
    public static function flush(): void
    {
        self::$map = null;
        self::$added = null;
    }

    /**
     * Whole sheet: normalized artist => bin label.
     *
     * @return array<string,string>
     */
    public function allArtists(): array
    {
        return $this->map();
    }

    /**
     * Sheet spelling of each artist (normalized => "AC/DC"), from
     * data/artist_genre_names.json. Falls back to the normalized key.
     */
    public function displayName(string $normalized): string
    {
        $added = $this->added();
        if (isset($added[$normalized])) {
            return $added[$normalized]['name'];
        }
        static $names = null;
        if ($names === null) {
            $path = __DIR__ . '/data/artist_genre_names.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $names = is_array($decoded) ? $decoded : [];
        }
        return $names[$normalized] ?? $normalized;
    }

    /** @return array<string,string> */
    private function map(): array
    {
        if (self::$map === null) {
            $path = __DIR__ . '/data/artist_genres.json';
            $json = is_file($path) ? file_get_contents($path) : false;
            $decoded = $json !== false ? json_decode($json, true) : null;
            self::$map = is_array($decoded) ? $decoded : [];
            // Staff additions from the Genre Bible page win over the sheet.
            foreach ($this->added() as $key => $a) {
                if ($a['genre'] === self::NOT_AN_ARTIST) {
                    unset(self::$map[$key]); // junk name: no genre
                } else {
                    self::$map[$key] = $a['genre'];
                }
            }
        }
        return self::$map;
    }
}
