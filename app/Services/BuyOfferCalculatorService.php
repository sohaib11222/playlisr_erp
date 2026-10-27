<?php

namespace App\Services;

class BuyOfferCalculatorService
{
    // Jon 2026-10-23: we still buy pricier items, but never pay as if one item
    // is worth more than this. A $1,200 record prices like a $350 one.
    const VALUE_CAP = 350;
    // Anything above this is a typo (a barcode in the price box), not a price.
    const VALUE_TYPO = 10000;
    // "1-9 items" lines: more than this many is a bulk lot.
    const INDIVIDUAL_MAX_QTY = 20;
    // Jon 2026-10-25: a signed record / CD pays this share of the signed copy's
    // sold value (up to VALUE_CAP), like posters and other valuable items.
    // Signed copies sell for a lot but slowly (4 used signed LPs sold in a year,
    // 23 on the shelf), and a signature can be fake.
    const SIGNED_PAY = 0.20;
    const SIGNED_SIGNOFF_VALUE = 100;
    // Jon 2026-10-25: "<format> - rare" lines are for items worth $15+, and pay
    // by how fast that category sells here (BuyDemandService speed tier).
    const RARE_MIN_VALUE = 15;
    const RARE_SPEED_PAY = ['slow' => 0.15, 'normal' => 0.20, 'fast' => 0.22, 'super_fast' => 0.25];
    // Single records at this median or more need a Discogs link / ID.
    const LINK_REQUIRED_FROM = 20;

    /**
     * Seeded rules from client sheet (phase 1).
     * cash_value = quantity * (base_amount from item OR discogs median) * multipliers.
     * credit_value = cash_value * credit_bonus_multiplier.
     */
    public function getRules()
    {
        return [
            'credit_bonus_multiplier' => 1.50,
            'individual_vinyl_standard_multiplier' => 0.10,
            // Condition multipliers — kept in worst→best order so the dropdown
            // sorts naturally bottom-up. Sarah 2026-05-06: 'Poor' added (0.10).
            'grade_multipliers' => [
                'Poor' => 0.10,
                'Fair' => 0.20,
                'Good' => 0.22,
                'Good+' => 0.25,
                'VG' => 0.65,
                'VG+' => 0.85,
                'Near Mint' => 0.95,
                'Mint' => 1.00,
            ],
            'item_types' => [
                'individual_vinyl' => ['label' => '1-9 items: Vinyl (Individual Discogs)', 'mode' => 'individual_discogs', 'default_grade' => 'VG+'],

                'bulk_vinyl_punk_metal_hiphop' => ['label' => '10+ Bulk Vinyl: Punk/Metal/Hip-Hop LP', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'default_grade' => 'VG+'],
                'bulk_vinyl_rock_alt_reggae_electronic' => ['label' => '10+ Bulk Vinyl: Rock/Alt/Reggae/Electronic', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'default_grade' => 'VG+'],
                'bulk_vinyl_rb_jazz' => ['label' => '10+ Bulk Vinyl: R&B/Jazz', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'default_grade' => 'VG+'],
                'bulk_vinyl_country' => ['label' => '10+ Bulk Vinyl: Country', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                'bulk_vinyl_folk' => ['label' => '10+ Bulk Vinyl: Folk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.35, 'default_grade' => 'VG+'],
                'bulk_vinyl_pop_old' => ['label' => '10+ Bulk Vinyl: Pop (Old)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                'bulk_vinyl_classical' => ['label' => '10+ Bulk Vinyl: Classical', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15, 'default_grade' => 'VG+'],
                'bulk_vinyl_soundtracks' => ['label' => '10+ Bulk Vinyl: Soundtracks', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                // Jon 2026-10-25: gaps vs what sells (90 days, both stores). Rate =
                // a reference rate x genre speed vs its format x its avg price vs
                // the format's, capped at 1.75x, rounded, conservative where thin.
                // Used LP reference $0.60 (avg used LP $16.89): Pop (New) 210 sold
                // $24.12 1.8x -> $1.00; Blues 86 $12.74 1.1x -> $0.50; Latin 116
                // $17.65 0.55x (2,110 in stock) -> $0.35.
                'bulk_vinyl_pop_new' => ['label' => '10+ Bulk Vinyl: Pop (New)', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'bulk_vinyl_blues' => ['label' => '10+ Bulk Vinyl: Blues', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'bulk_vinyl_latin' => ['label' => '10+ Bulk Vinyl: Latin', 'mode' => 'bulk_fixed', 'unit_rate' => 0.35],
                // Sealed LP reference $4.00 (avg sealed LP $38.99, ~10%): e.g. Pop 811
                // sold $41.95 2.3x -> $7.00, Hip-Hop 533 $45.71 1.1x -> $5.00, Rock 619
                // $35.42 0.7x -> $2.75, Punk 79 sold 916 in stock -> $1.00. Not graded.
                'bulk_vinyl_sealed_pop' => ['label' => '10+ Bulk SEALED Vinyl: Pop', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 7.00],
                'bulk_vinyl_sealed_metal' => ['label' => '10+ Bulk SEALED Vinyl: Metal', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 6.50],
                'bulk_vinyl_sealed_hiphop' => ['label' => '10+ Bulk SEALED Vinyl: Hip-Hop', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 5.00],
                'bulk_vinyl_sealed_electronic' => ['label' => '10+ Bulk SEALED Vinyl: Electronic / Dance', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 4.50],
                'bulk_vinyl_sealed_rnb' => ['label' => '10+ Bulk SEALED Vinyl: R&B / Soul / Funk', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 4.25],
                'bulk_vinyl_sealed_alt' => ['label' => '10+ Bulk SEALED Vinyl: Alt / Indie', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 4.00],
                'bulk_vinyl_sealed_jazz' => ['label' => '10+ Bulk SEALED Vinyl: Jazz', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 4.00],
                'bulk_vinyl_sealed_latin' => ['label' => '10+ Bulk SEALED Vinyl: Latin', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 3.50],
                'bulk_vinyl_sealed_soundtracks' => ['label' => '10+ Bulk SEALED Vinyl: Soundtracks', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 3.50],
                'bulk_vinyl_sealed_rock' => ['label' => '10+ Bulk SEALED Vinyl: Rock', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 2.75],
                'bulk_vinyl_sealed_newwave' => ['label' => '10+ Bulk SEALED Vinyl: New Wave / Post Punk', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 2.75],
                'bulk_vinyl_sealed_reggae' => ['label' => '10+ Bulk SEALED Vinyl: Reggae', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 2.00],
                'bulk_vinyl_sealed_country' => ['label' => '10+ Bulk SEALED Vinyl: Country', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 1.50],
                'bulk_vinyl_sealed_folk' => ['label' => '10+ Bulk SEALED Vinyl: Folk', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 1.00],
                'bulk_vinyl_sealed_punk' => ['label' => '10+ Bulk SEALED Vinyl: Punk', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 1.00],
                'bulk_vinyl_sealed_other' => ['label' => '10+ Bulk SEALED Vinyl: Other genres / mixed (Blues, Classical, Disco, K-pop…)', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 3.00],
                'bulk_vinyl_anything_else' => ['label' => '10+ Bulk Vinyl: Other genres / mixed (Gospel, World, K-pop, Children\'s…)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'default_grade' => 'VG'],

                'cd_general_used' => ['label' => 'CD: Other genres / mixed USED (Latin, Country, K-pop, World…)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],
                'cd_general_sealed' => ['label' => 'CD: Other genres / mixed SEALED (Latin, K-pop, World, Children\'s…)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30],
                // Sealed CD reference $0.60 (avg $17.95): Pop 207 sold 1.6x -> $1.00,
                // Electronic 132 1.2x -> $0.80, Blues 77 1.1x -> $0.50, Country 0.5x -> $0.40.
                'cd_pop_sealed' => ['label' => 'CD: Pop SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cd_electronic_sealed' => ['label' => 'CD: Electronic / Dance SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.80],
                'cd_blues_sealed' => ['label' => 'CD: Blues SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'cd_country_sealed' => ['label' => 'CD: Country SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                // Used CD (other was $0.15): Pop 137 sold 1.7x, Electronic 77 at $14.17.
                'cd_pop_used' => ['label' => 'CD: Pop USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],
                'cd_electronic_used' => ['label' => 'CD: Electronic / Dance USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],
                // Jon 2026-10-23: Punk / Metal / Hip-Hop CDs split into their own
                // types, priced off 90 days of sales (both stores). Used: Hip-Hop
                // 83 sold at $8.43 avg, 2.1x as fast as used CDs overall; Metal 40
                // at $9.19, 1.6x; Punk 23 at $16.04, 1.2x. Sealed: Hip-Hop 382 at
                // $20.55, 1.0x; Metal + Nu-Metal 266 at $15.90, 1.5x; Punk 17 at
                // $18.46, 1.7x. The combined types stay (retired) so old buys
                // still show their label.
                'cd_hiphop_used' => ['label' => 'CD: Hip-Hop USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.25],
                'cd_metal_used' => ['label' => 'CD: Metal USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cd_punk_used' => ['label' => 'CD: Punk USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cd_hiphop_sealed' => ['label' => 'CD: Hip-Hop SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50],
                'cd_metal_sealed' => ['label' => 'CD: Metal SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 2.25],
                'cd_punk_sealed' => ['label' => 'CD: Punk SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00],
                'cd_punk_metal_hiphop_used' => ['label' => 'CD: Punk/Metal/Hip-Hop USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'retired' => true],
                'cd_punk_metal_hiphop_sealed' => ['label' => 'CD: Punk/Metal/Hip-Hop SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'retired' => true],
                'cd_rock_alt_newwave_used' => ['label' => 'CD: Rock/Alt/New Wave USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30],
                'cd_rock_alt_newwave_sealed' => ['label' => 'CD: Rock/Alt/New Wave SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.60],
                'cd_rb_jazz_used' => ['label' => 'CD: R&B/Jazz USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20],
                'cd_rb_jazz_sealed' => ['label' => 'CD: R&B/Jazz SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                'cd_soundtracks_used' => ['label' => 'CD: Soundtracks USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],
                'cd_soundtracks_sealed' => ['label' => 'CD: Soundtracks SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30],
                'cd_classical_opera_used' => ['label' => 'CD: Classical/Opera USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.10],
                'cd_classical_opera_sealed' => ['label' => 'CD: Classical/Opera SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20],
                'cd_nonmusic_used' => ['label' => 'CD: Non-Music USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05],
                'cd_nonmusic_sealed' => ['label' => 'CD: Non-Music SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.10],
                'cd_no_case' => ['label' => 'CD: No Case', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05],
                'cd_boxset_used' => ['label' => 'CD: Boxset USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.50],
                'cd_boxset_sealed' => ['label' => 'CD: Boxset SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 3.00],

                // Jon 2026-10-23: used cassettes split by genre, priced off 90 days
                // of sales at both stores (avg used cassette $10.24, turnover 0.18).
                // R&B/Soul 114 sold at ~$9.35, 1.4x as fast as used cassettes overall;
                // Metal 10 at $12.70, 1.9x (only 29 in stock); Hip-Hop 32 at $12.25
                // but 0.7x (245 in stock); Punk 3 sold; Rock 94 at $8.51, 0.9x; Jazz
                // 17 at $8.06, 0.3x (276 in stock). The combined types stay (retired)
                // so old buys keep their label.
                'cassette_hiphop_used' => ['label' => 'Cassette: Hip-Hop USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
                'cassette_metal_used' => ['label' => 'Cassette: Metal USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cassette_punk_used' => ['label' => 'Cassette: Punk USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
                'cassette_rnb_used' => ['label' => 'Cassette: R&B/Soul USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
                'cassette_rock_used' => ['label' => 'Cassette: Rock USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'cassette_jazz_used' => ['label' => 'Cassette: Jazz USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.35],
                'cassette_hh_metal_punk' => ['label' => 'Cassette: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'retired' => true],
                'cassette_rb_jazz_rock' => ['label' => 'Cassette: R&B/Jazz/Rock', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'retired' => true],
                // Alt Rock cassettes: 29 used + 22 sealed sold, ~$13.90, 2x as fast.
                'cassette_alt' => ['label' => 'Cassette: Alt Rock (used or sealed)', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cassette_everything_else' => ['label' => 'Cassette: Other genres / mixed / sealed (Pop, Country, Electronic, Soundtracks…)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],

                'rpm45_hh_metal_punk' => ['label' => '45 RPM: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'rpm45_newwave_rock_alt' => ['label' => '45 RPM: New Wave/Rock/Alt', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                'rpm45_without_sleeve' => ['label' => '45 RPM: Without Sleeve', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05],
                // R&B/Soul 45s: our best-selling 45 genre, 102 sold $9.15, 1.35x.
                'rpm45_rnb' => ['label' => '45 RPM: R&B / Soul / Funk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'rpm45_anything_else' => ['label' => '45 RPM: Other genres / mixed (Pop, Country, Jazz…)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],

                // Jon 2026-10-23: DVD, book and magazine subcategories priced off 90
                // days of sales at both stores: rate = old base x (subcategory speed /
                // category speed) x (its avg price / category avg), rounded, kept
                // conservative where sales are thin. DVD/Blu-ray avg $7.28, turnover
                // 0.16: e.g. Action 227 sold $7.30 1.4x -> $0.20; Music 18 sold, 297 in
                // stock 0.4x -> $0.10; Romance 2 sold -> $0.05. The general types are
                // now "other / mixed" (no speed adjustment on Beta).
                'dvd_used' => ['label' => 'DVD Used: other genres / mixed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15, 'no_grading' => true],
                'dvd_action_used' => ['label' => 'DVD Used: Action / Adventure', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.20],
                'dvd_animation_used' => ['label' => 'DVD Used: Animation', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.25],
                'dvd_boxset_used' => ['label' => 'DVD Used: Box Sets', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.60],
                'dvd_comedy_used' => ['label' => 'DVD Used: Comedy', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.10],
                'dvd_documentary_used' => ['label' => 'DVD Used: Documentary', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.15],
                'dvd_drama_used' => ['label' => 'DVD Used: Drama', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.10],
                'dvd_family_used' => ['label' => 'DVD Used: Family', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.15],
                'dvd_fantasy_used' => ['label' => 'DVD Used: Fantasy', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.30],
                'dvd_musical_used' => ['label' => 'DVD Used: Musical', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.15],
                'dvd_romance_used' => ['label' => 'DVD Used: Romance', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.05],
                'dvd_thriller_used' => ['label' => 'DVD Used: Thriller', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.15],
                'dvd_tv_used' => ['label' => 'DVD Used: TV', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.20],
                'dvd_sealed' => ['label' => 'DVD Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40, 'no_grading' => true],
                'dvd_criterion_used' => ['label' => 'DVD Criterion Used', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
                'dvd_music_used' => ['label' => 'DVD Used: Music', 'mode' => 'bulk_fixed', 'unit_rate' => 0.10, 'no_grading' => true],
                'dvd_horror_used' => ['label' => 'DVD Used: Horror', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],
                'dvd_scifi_used' => ['label' => 'DVD Used: Sci-Fi', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30, 'no_grading' => true],
                'bluray_used' => ['label' => 'Blu-ray Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],
                'bluray_sealed' => ['label' => 'Blu-ray Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],
                '4k_used' => ['label' => '4K Used', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'no_grading' => true],
                'vhs_used' => ['label' => 'VHS Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],
                'vhs_horror_used' => ['label' => 'VHS Horror Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'no_grading' => true],
                'laserdisc' => ['label' => 'LaserDisc', 'mode' => 'bulk_fixed', 'unit_rate' => 0.35, 'no_grading' => true],

                'videogame_with_case' => ['label' => 'Video Game (with case)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],
                'videogame_no_case' => ['label' => 'Video Game (no case)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],

                // Books avg $9.26 turnover 0.26 (Manga 2.0x, Zines 1.9x, Fantasy 0.1x);
                // magazines avg $13.04 turnover 0.24 (Playboy 3.0x, Pop Culture 1.5x).
                'magazine' => ['label' => 'Magazine: other / mixed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'no_grading' => true],
                'magazine_mad' => ['label' => 'Magazine: Mad', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.25],
                'magazine_playboy' => ['label' => 'Magazine: Playboy', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 1.50],
                'magazine_pop_culture' => ['label' => 'Magazine: Pop Culture', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.85],
                'book' => ['label' => 'Book: other / mixed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30, 'no_grading' => true],
                'book_artist_band' => ['label' => 'Book: Artist & Band', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.55],
                'book_bio' => ['label' => 'Book: Biographies & Memoirs', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.35],
                'book_comic' => ['label' => 'Book: Comic Books', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.40],
                'book_fantasy' => ['label' => 'Book: Fantasy', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.10],
                'book_historical' => ['label' => 'Book: Historical Fiction', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.25],
                'book_manga' => ['label' => 'Book: Manga', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.75],
                'book_music_history' => ['label' => 'Book: Music History & Culture', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.35],
                'book_mystery' => ['label' => 'Book: Mystery / Thriller', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.20],
                'book_zine' => ['label' => 'Book: Zines (indie & DIY)', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.50],
                'coffee_table_book' => ['label' => 'Coffee Table Book', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],

                'hat_cap' => ['label' => 'Hat / Cap (Snapback)', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'hat_fitted' => ['label' => 'Hat (Fitted)', 'mode' => 'bulk_fixed', 'unit_rate' => 3.00, 'no_grading' => true],
                'tshirt' => ['label' => 'T-Shirt', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'tanktop' => ['label' => 'Tank Top', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'no_grading' => true],
                'sweatshirt' => ['label' => 'Sweatshirt', 'mode' => 'bulk_fixed', 'unit_rate' => 3.50, 'no_grading' => true],

                // Jon 2026-10-25: signed / rare posters by their real sold value, the

                // "items worth more" rule: 20% of the value (25% if posters sell super

                // fast here), $350 cap. Ordinary posters stay on the flat $2 line.

                'poster_value' => ['label' => 'Poster: signed / rare (enter its sold value)', 'mode' => 'ebay_margin', 'no_grading' => true, 'fixed_hv' => true],
                // Jon 2026-10-25: rare VHS / DVD / book / comic ($15+) by its eBay
                // median sold value, paid by that category's speed (RARE_SPEED_PAY).
                'rare_vhs' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'VHS - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_dvd' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'DVD - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_book' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'Book - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_comic' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'Comic - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                // Jon 2026-10-25: rare cards ($15+) and card packs by their eBay
                // value, paid by the subcategory's speed (packs: no minimum).
                'rare_mlb' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'MLB - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_nba' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'NBA - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_nfl' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'NFL - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'rare_pokemon' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'Pokemon - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                'card_pack' => ['label' => 'Card Packs', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                // Jon 2026-10-25: framed art, flat $3.50; rare pieces by their value.
                'framed_art' => ['label' => 'Framed Art', 'mode' => 'bulk_fixed', 'unit_rate' => 3.50, 'no_grading' => true],
                'rare_framed_art' => ['min_value' => self::RARE_MIN_VALUE, 'label' => 'Framed Art - rare', 'mode' => 'ebay_margin', 'no_grading' => true, 'speed_pay' => true],
                // Jon 2026-10-25: signed items get their own line (was a checkbox
                // on single records): SIGNED_PAY of the signed copy's sold value.
                'signed_item' => ['label' => 'Signed item (enter the signed copy\'s sold value)', 'mode' => 'signed_value', 'no_grading' => true],

                'poster_2x3' => ['label' => "Poster 2'x3'", 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],

                'pokemon_regular' => ['label' => 'Pokemon Card (regular)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05, 'no_grading' => true],
                'pokemon_shiny' => ['label' => 'Pokemon Card (shiny)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.07, 'no_grading' => true],
                'sports_card_2010_plus' => ['label' => 'Sports Card 2010+', 'mode' => 'bulk_fixed', 'unit_rate' => 0.03, 'no_grading' => true],
                'sports_card_1980_2010' => ['label' => 'Sports Card 1980-2010', 'mode' => 'bulk_fixed', 'unit_rate' => 0.015, 'no_grading' => true],

                'turntable_technics_sl1200' => ['label' => 'Technics SL-1200 Turntable', 'mode' => 'bulk_fixed', 'unit_rate' => 125.00, 'no_grading' => true],
                'turntable_audio_technica' => ['label' => 'Audio Technica Direct Drive Turntable', 'mode' => 'bulk_fixed', 'unit_rate' => 75.00, 'no_grading' => true],
                'turntable_crosley_victrola' => ['label' => 'Crosley / Victrola Turntable', 'mode' => 'bulk_fixed', 'unit_rate' => 20.00, 'no_grading' => true],
                'receiver' => ['label' => 'Receiver', 'mode' => 'bulk_fixed', 'unit_rate' => 20.00, 'no_grading' => true],
                'dvd_player' => ['label' => 'DVD Player', 'mode' => 'bulk_fixed', 'unit_rate' => 5.00, 'no_grading' => true],
                'camcorder_digital' => ['label' => 'Digital Video Camcorder', 'mode' => 'bulk_fixed', 'unit_rate' => 10.00, 'no_grading' => true],
                // Sarah 2026-07-02: cameras are priced off eBay value, not a flat
                // rate — the cashier types the eBay value into the median-price
                // column and we pay a % of it: 15% under $30, 20% at $30+. The
                // 50/75/95% offer ladder then negotiates down from there, so the
                // final offer lands at ~15% / ~20% of value.
                'camera' => ['label' => 'Camera', 'mode' => 'value_percent', 'no_grading' => true, 'value_tiers' => [
                    ['under' => 30, 'percent' => 0.15],
                    ['under' => null, 'percent' => 0.20],
                ]],
                // Jon 2026-10-23, Beta buy form only: something valuable priced off
                // recent eBay sold data. We pay (100 - margin)% of the eBay sold
                // value; the margin comes from how fast the line's category sells
                // (Settings -> Buy Margins, see BuyMarginSettings). Bulk is unchanged.
                'ebay_value' => ['label' => 'Valuable item (eBay sold value)', 'mode' => 'ebay_margin', 'no_grading' => true, 'beta_only' => true],
                'cassette_player' => ['label' => 'Cassette Player', 'mode' => 'bulk_fixed', 'unit_rate' => 5.00, 'no_grading' => true],
                'cd_player' => ['label' => 'CD Player', 'mode' => 'bulk_fixed', 'unit_rate' => 6.00, 'no_grading' => true],
                'flatscreen_tv' => ['label' => 'Flatscreen TV', 'mode' => 'bulk_fixed', 'unit_rate' => 13.00, 'no_grading' => true],

                'mask' => ['label' => 'Mask', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'furry_doll' => ['label' => 'Furry Doll', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
                'funko_pop' => ['label' => 'Funko Pop', 'mode' => 'bulk_fixed', 'unit_rate' => 1.50, 'no_grading' => true],
                'board_game' => ['label' => 'Board Game', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
                // Toys avg $6.46, turnover 0.88 (fast): Action Figures 73 sold $6.97
                // 1.65x -> $1.00; Squishies 260 sold ~$4.80 -> $0.50; other toys
                // (blind boxes, keychains, misc) -> $0.50. Pins 19 sold $15.79.
                'toy_action_figure' => ['label' => 'Toy: Action Figure', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 1.00],
                'toy_squishy' => ['label' => 'Toy: Squishy', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.50],
                'toy_other' => ['label' => 'Toy: Other / mixed (blind boxes, keychains…)', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.50],
                // Jon 2026-10-25: pins come in bags, mostly not $15 pins: $0.33 each.
                'pin' => ['label' => 'Pin', 'mode' => 'bulk_fixed', 'no_grading' => true, 'unit_rate' => 0.33],
            ],
        ];
    }

    /**
     * @param bool $beta include Beta-form-only types (eBay value line)
     * @param bool $retired include retired types (labels for old buys)
     */
    public function getItemTypesForDropdown($beta = false, $retired = false)
    {
        $rules = $this->getRules();
        $output = [];
        foreach ($rules['item_types'] as $key => $config) {
            if ((!$beta && !empty($config['beta_only'])) || (!$retired && !empty($config['retired']))) {
                continue;
            }
            $output[$key] = $config['label'];
        }
        // Alphabetize the dropdown by label (natural, case-insensitive) so
        // cashiers can scan the media types in order instead of by group.
        asort($output, SORT_NATURAL | SORT_FLAG_CASE);
        return $output;
    }

    /**
     * Jon 2026-10-25: VG+ was left on 72% of graded lines (89% of units) and
     * grades mostly went up, rarely down. Single records have no default (the
     * cashier must pick); bulk lots default to VG (mixed condition); sealed
     * bulk stays VG+. Null = no grading or must pick.
     */
    public function defaultGradeFor($itemType)
    {
        $cfg = $this->getRules()['item_types'][$itemType] ?? null;
        if (!$cfg || !empty($cfg['no_grading']) || $cfg['mode'] !== 'bulk_fixed') {
            return null;
        }
        return strpos($itemType, '_sealed') !== false ? 'VG+' : 'VG';
    }

    /** A bulk lot whose Near Mint / Mint grade needs a manager (sealed lots are mint by nature). */
    public function isBulkGraded($itemType)
    {
        $cfg = $this->getRules()['item_types'][$itemType] ?? null;
        return $cfg && empty($cfg['no_grading']) && $cfg['mode'] === 'bulk_fixed' && strpos($itemType, '_sealed') === false;
    }

    public function getGradesForDropdown()
    {
        return array_keys($this->getRules()['grade_multipliers']);
    }

    /**
     * Pick the pay-out percentage for a value_percent item (e.g. cameras) from
     * its tier table. Tiers are checked in order; the first whose `under`
     * threshold the value falls below wins, and a null `under` is the catch-all
     * top tier.
     *
     * @param  float|int|string  $value
     * @param  array<int, array{under: float|int|null, percent: float}>  $tiers
     */
    protected function percentForValue($value, array $tiers)
    {
        $value = (float) $value;
        foreach ($tiers as $tier) {
            $under = $tier['under'] ?? null;
            if ($under === null || $value < (float) $under) {
                return (float) ($tier['percent'] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * @param  callable|null  $demand  Beta buy form only: fn(array $line, $index) returning
     *   ['multiplier' => float, 'level' => string, 'reason' => ?string]. The line's cash
     *   value is multiplied by it, so the bump is part of the calculated (system) number
     *   the overpay guard compares against. Null = today's math, unchanged output.
     */
    public function calculate(array $lines, array $offerInputs = [], callable $demand = null)
    {
        $rules = $this->getRules();
        $normalized = [];
        $cashTotal = 0;
        $demandBonus = 0;

        foreach ($lines as $i => $line) {
            $itemType = $line['item_type'] ?? '';
            if (empty($itemType) || empty($rules['item_types'][$itemType])) {
                continue;
            }

            $cfg = $rules['item_types'][$itemType];
            $quantity = max(0, (float) ($line['quantity'] ?? 0));
            if ($quantity <= 0) {
                continue;
            }

            // create.blade.php pre-renders 7 blank individual_vinyl rows
            // (quantity 1, no title/link/price) so cashiers don't have to
            // click "Add line" for a typical haul. Skip whichever of those
            // never got touched, so unused rows don't get saved as items.
            if ($itemType === 'individual_vinyl'
                && $quantity == 1
                && trim((string) ($line['title'] ?? '')) === ''
                && trim((string) ($line['discogs_link'] ?? '')) === ''
                && trim((string) ($line['discogs_median_price'] ?? '')) === ''
            ) {
                continue;
            }

            // Old drafts flagged signed single records with a checkbox.
            $signed = $itemType === 'signed_item' || ($itemType === 'individual_vinyl' && !empty($line['signed']));
            $noGrading = !empty($cfg['no_grading']) || $signed;
            $grade = (string) ($line['condition_grade'] ?? '');
            if ($grade === '' || !isset($rules['grade_multipliers'][$grade])) {
                // No grade picked: the type's default, VG for single records
                // (validation blocks those before Calculate anyway).
                $grade = $this->defaultGradeFor($itemType) ?: 'VG';
            }
            $gradeMultiplier = $noGrading ? 1.0 : (float) ($rules['grade_multipliers'][$grade] ?? 1);
            $standardMultiplier = (float) ($line['standard_multiplier'] ?? $rules['individual_vinyl_standard_multiplier']);
            $discogsMedian = (float) ($line['discogs_median_price'] ?? 0);
            // Priced on at most VALUE_CAP per item; the typed value is still saved.
            $payValue = min($discogsMedian, (float) self::VALUE_CAP);
            if ($discogsMedian > self::VALUE_CAP && $cfg['mode'] === 'individual_discogs') {
                // The form's 31% tier starts at $375, above the cap.
                $standardMultiplier = min($standardMultiplier, 0.27);
            }
            $unitRate = isset($cfg['unit_rate']) ? (float) $cfg['unit_rate'] : (float) ($line['unit_rate'] ?? 0);

            if ($signed) {
                // Signed: SIGNED_PAY of the signed copy's value, no grade, no
                // speed adjustments (see the demand block below).
                $standardMultiplier = self::SIGNED_PAY;
                $cashLine = $quantity * $payValue * self::SIGNED_PAY;
            } elseif ($cfg['mode'] === 'individual_discogs') {
                $cashLine = $quantity * $payValue * $gradeMultiplier * $standardMultiplier;
            } elseif ($cfg['mode'] === 'ebay_margin') {
                // Filled in by the demand hook below (it knows the margin).
                $standardMultiplier = 0;
                $cashLine = 0;
            } elseif ($cfg['mode'] === 'value_percent') {
                // Value-based: pay a % of the eBay value the cashier entered into
                // the median-price column. The % steps by value (see value_tiers).
                $standardMultiplier = $this->percentForValue($payValue, $cfg['value_tiers'] ?? []);
                $cashLine = $quantity * $payValue * $standardMultiplier;
            } else {
                $cashLine = $quantity * $unitRate * $gradeMultiplier;
            }

            $cashLine = round($cashLine, 2);
            $demandInfo = null;
            if ($cfg['mode'] === 'ebay_margin') {
                // eBay value line: the hook returns the margin for the line's
                // category speed. No hook (normal form) = default normal margin.
                // Never a demand bonus on top: the margin is the whole price.
                try {
                    $raw = ($demand !== null && $discogsMedian > 0) ? $demand($line, $i) : null;
                } catch (\Throwable $e) {
                    $raw = null;
                }
                $raw = is_array($raw) ? $raw : [];
                $hv = is_array($raw['hv'] ?? null) ? $raw['hv'] + BuyMarginSettings::DEFAULTS : BuyMarginSettings::DEFAULTS;
                $margin = isset($raw['margin']) ? (float) $raw['margin'] : (float) BuyMarginSettings::DEFAULTS['normal'];
                $margin = max((float) $hv['floor'], min(95.0, $margin));
                $superFast = !empty($raw['super_fast']);
                // Items worth $20+ pay at most 20% (25% if super fast), Jon 10/23.
                // fixed_hv (valuable-poster line): always the $20+ rule, so a cheap
                // poster typed here can't get the higher under-$20 rate.
                $tierValue = !empty($cfg['fixed_hv']) ? max($payValue, (float) $hv['hv_min']) : $payValue;
                $standardMultiplier = BuyMarginSettings::payFraction($hv, $margin, $superFast, $tierValue);
                if (!empty($cfg['speed_pay'])) {
                    $speed = $superFast ? 'super_fast' : (string) ($raw['tier'] ?? 'normal');
                    $standardMultiplier = self::RARE_SPEED_PAY[$speed] ?? self::RARE_SPEED_PAY['normal'];
                }
                $cashLine = round($quantity * $payValue * $standardMultiplier, 2);
                $demandInfo = [
                    'demand_multiplier' => 1.0,
                    'demand_level' => 'margin',
                    'demand_reason' => $raw['reason'] ?? null,
                    'demand_genre' => (string) ($line['genre'] ?? '') ?: null,
                    // Effective margin on this line (after the $20+ cap / boost).
                    'demand_margin' => round(100 - $standardMultiplier * 100, 2),
                    'demand_tier' => $raw['tier'] ?? 'normal',
                    'demand_super_fast' => $superFast,
                    'demand_base_margin' => $margin,
                ];
            } elseif ($demand !== null && !$signed) {
                try {
                    // A $0 line can't earn a bonus, so skip its lookups.
                    $raw = $cashLine > 0 ? $demand($line, $i) : null;
                } catch (\Throwable $e) {
                    $raw = null;
                }
                $raw = is_array($raw) ? $raw : [];
                // Down to 25% (huge lot), up to +50% (the most the fast-seller
                // bonus setting allows), whatever the hook says.
                $mult = min(1.50, max(0.25, (float) ($raw['multiplier'] ?? 1.0)));
                $baseLine = $cashLine;
                $cashLine = round($cashLine * $mult, 2);
                $demandBonus += $cashLine - $baseLine;
                $demandInfo = [
                    'demand_multiplier' => $mult,
                    // Hot / warm stay as info (base pay); cool / cold carry the cut.
                    'demand_level' => $mult < 1.0 ? ($raw['level'] ?? 'cool') : ($mult > 1.0 ? ($raw['level'] ?? 'warm') : (in_array($raw['level'] ?? '', ['hot', 'warm'], true) ? $raw['level'] : 'normal')),
                    'demand_reason' => $raw['reason'] ?? null,
                    'demand_genre' => (string) ($line['genre'] ?? '') ?: null,
                    'demand_last_sold' => !empty($line['discogs_never_sold']) ? 'never' : ((string) ($line['discogs_last_sold'] ?? '') ?: null),
                ];
            }
            $cashTotal += $cashLine;
            $creditLine = round($cashLine * (float) $rules['credit_bonus_multiplier'], 2);

            $row = [
                'line_order' => $i,
                'item_type' => $itemType,
                // Signed lines keep a "(signed)" mark so they can be found later.
                'title' => $signed && stripos((string) ($line['title'] ?? ''), 'sign') === false
                    ? trim(((string) ($line['title'] ?? '')) . ' (signed)')
                    : ($line['title'] ?? null),
                'discogs_link' => $line['discogs_link'] ?? null,
                'condition_grade' => $noGrading ? null : $grade,
                'quantity' => $quantity,
                'discogs_median_price' => $discogsMedian > 0 ? $discogsMedian : null,
                'grade_multiplier' => $gradeMultiplier,
                'standard_multiplier' => $standardMultiplier,
                'unit_rate' => $unitRate > 0 ? $unitRate : null,
                'line_cash_total' => $cashLine,
                'line_credit_total' => $creditLine,
                'disposition' => $line['disposition'] ?? null,
            ];
            if ($demandInfo !== null) {
                $row += $demandInfo;
            }
            $normalized[] = $row;
        }

        $cashTotal = round($cashTotal, 2);
        $creditBonus = (float) $rules['credit_bonus_multiplier'];
        $creditTotal = round($cashTotal * $creditBonus, 2);

        // Credit offers are always credit_bonus_multiplier × the cash offer at
        // the same negotiation tier — never taken from the submitted *_credit
        // input directly. Previously a cashier typing a negotiated
        // starting/second/final CASH figure left the paired credit figure at
        // its old auto-filled value, so store credit quietly stopped being
        // 1.5x cash the moment any negotiation happened (the normal case).
        // Deriving credit from the (possibly overridden) cash figure here
        // makes the ratio hold everywhere, not just on the untouched default.
        $startingCash = isset($offerInputs['starting_offer_cash']) && $offerInputs['starting_offer_cash'] !== '' ? (float) $offerInputs['starting_offer_cash'] : self::roundOffer($cashTotal * 0.50);
        $secondCash = isset($offerInputs['second_offer_cash']) && $offerInputs['second_offer_cash'] !== '' ? (float) $offerInputs['second_offer_cash'] : self::roundOffer($cashTotal * 0.75);
        $finalCash = isset($offerInputs['final_offer_cash']) && $offerInputs['final_offer_cash'] !== '' ? (float) $offerInputs['final_offer_cash'] : self::roundOffer($cashTotal * 0.95);

        $result = [
            'lines' => $normalized,
            'collection_summary' => $this->summarizeCollection($normalized),
            'calculated_cash_total' => $cashTotal,
            'calculated_credit_total' => $creditTotal,
            'starting_offer_cash' => $startingCash,
            'starting_offer_credit' => round($startingCash * $creditBonus, 2),
            'second_offer_cash' => $secondCash,
            'second_offer_credit' => round($secondCash * $creditBonus, 2),
            'final_offer_cash' => $finalCash,
            'final_offer_credit' => round($finalCash * $creditBonus, 2),
        ];
        if ($demand !== null) {
            $result['demand_bonus_cash'] = round($demandBonus, 2);
        }

        return $result;
    }

    /**
     * Jon 2026-10-26: lines priced off an eBay value need the link to eBay's
     * sold results. eBay blocks our server from reading its pages, so a link
     * counts as sold only when it's an eBay search with the "Sold items"
     * filter on (LH_Sold=1); a single /itm/ page can't be proven sold.
     */
    public function requiresEbaySoldLink($itemType)
    {
        $cfg = $this->getRules()['item_types'][$itemType] ?? null;
        return $cfg && in_array($cfg['mode'], ['ebay_margin', 'value_percent'], true);
    }

    public static function isEbaySoldUrl($url)
    {
        $parts = parse_url(trim((string) $url));
        if (empty($parts['host']) || !preg_match('/(^|\.)ebay\.[a-z.]+$/i', $parts['host'])) {
            return false;
        }
        parse_str($parts['query'] ?? '', $q);
        $q = array_change_key_case($q, CASE_LOWER);
        return (string) ($q['lh_sold'] ?? '') === '1';
    }

    /**
     * Jon 2026-10-26: suggested cash offers are whole dollars ($9.45 -> $9,
     * $9.58 -> $10), at least $1 when there's anything to pay for. Credit stays
     * exactly credit_bonus x the cash offer.
     */
    public static function roundOffer($amount)
    {
        $amount = (float) $amount;
        return $amount > 0 ? max(1.0, round($amount)) : 0.0;
    }

    /**
     * Aggregate line rows for buy-record display (format counts + condition buckets).
     *
     * @param  array<int, array<string, mixed>>  $normalizedLines
     * @return array{format_counts: array<string, float>, condition_buckets: array<string, float>}
     */
    public function summarizeCollection(array $normalizedLines)
    {
        $formats = [
            'lp' => 0.0,
            'rpm45' => 0.0,
            'cd' => 0.0,
            'cassette' => 0.0,
            'dvd' => 0.0,
            'bluray' => 0.0,
            'vhs' => 0.0,
            'video_game' => 0.0,
            'apparel' => 0.0,
            'print' => 0.0,
            'trading_card' => 0.0,
            'equipment' => 0.0,
            'toy' => 0.0,
            'other' => 0.0,
        ];

        $buckets = [
            'mint_nm' => 0.0,
            'vg_plus_vg' => 0.0,
            'g_plus_below' => 0.0,
        ];

        $equipmentKeys = ['receiver', 'dvd_player', 'camcorder_digital', 'camera', 'cassette_player', 'cd_player', 'flatscreen_tv'];
        $apparelKeys = ['hat_cap', 'hat_fitted', 'tshirt', 'tanktop', 'sweatshirt'];
        $printKeys = ['magazine', 'book', 'coffee_table_book'];
        $isPrint = function ($t) use ($printKeys) {
            return in_array($t, $printKeys, true) || strpos($t, 'book_') === 0 || strpos($t, 'magazine_') === 0;
        };
        $toyKeys = ['mask', 'furry_doll', 'funko_pop', 'board_game', 'toy_action_figure', 'toy_squishy', 'toy_other'];

        foreach ($normalizedLines as $line) {
            $t = (string) ($line['item_type'] ?? '');
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if (strpos($t, 'rpm45_') === 0) {
                $formats['rpm45'] += $qty;
            } elseif (strpos($t, 'cd_player') === 0) {
                $formats['equipment'] += $qty;
            } elseif (strpos($t, 'cd_') === 0) {
                $formats['cd'] += $qty;
            } elseif (strpos($t, 'cassette_player') === 0) {
                $formats['equipment'] += $qty;
            } elseif (strpos($t, 'cassette_') === 0) {
                $formats['cassette'] += $qty;
            } elseif (strpos($t, 'dvd_') === 0 && $t !== 'dvd_player') {
                $formats['dvd'] += $qty;
            } elseif (strpos($t, 'bluray_') === 0 || $t === '4k_used') {
                $formats['bluray'] += $qty;
            } elseif (strpos($t, 'vhs_') === 0) {
                $formats['vhs'] += $qty;
            } elseif (strpos($t, 'videogame_') === 0) {
                $formats['video_game'] += $qty;
            } elseif (in_array($t, $apparelKeys, true)) {
                $formats['apparel'] += $qty;
            } elseif ($isPrint($t)) {
                $formats['print'] += $qty;
            } elseif (strpos($t, 'pokemon_') === 0 || strpos($t, 'sports_card_') === 0) {
                $formats['trading_card'] += $qty;
            } elseif (strpos($t, 'turntable_') === 0 || in_array($t, $equipmentKeys, true)) {
                $formats['equipment'] += $qty;
            } elseif (in_array($t, $toyKeys, true)) {
                $formats['toy'] += $qty;
            } elseif ($t === 'poster_2x3') {
                $formats['other'] += $qty;
            } elseif ($t === 'individual_vinyl' || strpos($t, 'bulk_vinyl_') === 0) {
                $formats['lp'] += $qty;
            } else {
                $formats['other'] += $qty;
            }

            $grade = (string) ($line['condition_grade'] ?? '');
            if ($grade === 'Mint' || $grade === 'Near Mint') {
                $buckets['mint_nm'] += $qty;
            } elseif ($grade === 'VG+' || $grade === 'VG') {
                $buckets['vg_plus_vg'] += $qty;
            } elseif ($grade === '') {
                // ungraded items (equipment, apparel, etc.) — don't count in any bucket
                continue;
            } else {
                $buckets['g_plus_below'] += $qty;
            }
        }

        foreach ($formats as $k => $v) {
            $formats[$k] = round($v, 4);
        }
        foreach ($buckets as $k => $v) {
            $buckets[$k] = round($v, 4);
        }

        return [
            'format_counts' => $formats,
            'condition_buckets' => $buckets,
        ];
    }
}
