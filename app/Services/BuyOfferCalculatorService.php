<?php

namespace App\Services;

class BuyOfferCalculatorService
{
    /**
     * Seeded rules from client sheet (phase 1).
     * cash_value = quantity * (base_amount from item OR discogs median) * multipliers.
     * credit_value = cash_value * credit_bonus_multiplier.
     */
    public function getRules()
    {
        return [
            'credit_bonus_multiplier' => 1.20,
            'individual_vinyl_standard_multiplier' => 0.10,
            'grade_multipliers' => [
                'Fair' => 0.20,
                'Good' => 0.22,
                'Good+' => 0.25,
                'VG' => 0.65,
                'VG+' => 0.85,
                'Near Mint' => 0.95,
                'Mint' => 1.00,
            ],
            'item_types' => [
                'individual_vinyl' => ['label' => 'Vinyl (Individual Discogs)', 'mode' => 'individual_discogs', 'default_grade' => 'VG+'],

                'bulk_vinyl_punk_metal_hiphop' => ['label' => 'Bulk Vinyl: Punk/Metal/Hip-Hop LP', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'default_grade' => 'VG+'],
                'bulk_vinyl_rock_alt_reggae_electronic' => ['label' => 'Bulk Vinyl: Rock/Alt/Reggae/Electronic', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'default_grade' => 'VG+'],
                'bulk_vinyl_rb_jazz' => ['label' => 'Bulk Vinyl: R&B/Jazz', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'default_grade' => 'VG+'],
                'bulk_vinyl_country' => ['label' => 'Bulk Vinyl: Country', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                'bulk_vinyl_folk' => ['label' => 'Bulk Vinyl: Folk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.35, 'default_grade' => 'VG+'],
                'bulk_vinyl_pop_old' => ['label' => 'Bulk Vinyl: Pop (Old)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                'bulk_vinyl_classical' => ['label' => 'Bulk Vinyl: Classical', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15, 'default_grade' => 'VG+'],
                'bulk_vinyl_soundtracks' => ['label' => 'Bulk Vinyl: Soundtracks', 'mode' => 'bulk_fixed', 'unit_rate' => 0.20, 'default_grade' => 'VG+'],
                'bulk_vinyl_anything_else' => ['label' => 'Bulk Vinyl: Anything Else', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'default_grade' => 'VG'],

                'cd_general_used' => ['label' => 'CD: General USED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],
                'cd_general_sealed' => ['label' => 'CD: General SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 0.30],
                'cd_punk_metal_hiphop_used' => ['label' => 'CD: Punk/Metal/Hip-Hop USED', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'cd_punk_metal_hiphop_sealed' => ['label' => 'CD: Punk/Metal/Hip-Hop SEALED', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00],
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

                'cassette_hh_metal_punk' => ['label' => 'Cassette: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
                'cassette_rb_jazz_rock' => ['label' => 'Cassette: R&B/Jazz/Rock', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'cassette_everything_else' => ['label' => 'Cassette: Everything Else', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],

                'rpm45_hh_metal_punk' => ['label' => '45 RPM: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'rpm45_newwave_rock_alt' => ['label' => '45 RPM: New Wave/Rock/Alt', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                'rpm45_without_sleeve' => ['label' => '45 RPM: Without Sleeve', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05],
                'rpm45_anything_else' => ['label' => '45 RPM: Anything Else', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],

                'dvd_used' => ['label' => 'DVD Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15, 'no_grading' => true],
                'dvd_sealed' => ['label' => 'DVD Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40, 'no_grading' => true],
                'dvd_criterion_used' => ['label' => 'DVD Criterion Used', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
                'bluray_used' => ['label' => 'Blu-ray Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],
                'bluray_sealed' => ['label' => 'Blu-ray Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],
                '4k_used' => ['label' => '4K Used', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'no_grading' => true],
                'vhs_used' => ['label' => 'VHS Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],
                'vhs_horror_used' => ['label' => 'VHS Horror Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50, 'no_grading' => true],

                'videogame_with_case' => ['label' => 'Video Game (with case)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],
                'videogame_no_case' => ['label' => 'Video Game (no case)', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25, 'no_grading' => true],

                'magazine' => ['label' => 'Magazine', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],
                'book' => ['label' => 'Book', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40, 'no_grading' => true],
                'coffee_table_book' => ['label' => 'Coffee Table Book', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75, 'no_grading' => true],

                'hat_cap' => ['label' => 'Hat / Cap (Snapback)', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'hat_fitted' => ['label' => 'Hat (Fitted)', 'mode' => 'bulk_fixed', 'unit_rate' => 3.00, 'no_grading' => true],
                'tshirt' => ['label' => 'T-Shirt', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'tanktop' => ['label' => 'Tank Top', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00, 'no_grading' => true],
                'sweatshirt' => ['label' => 'Sweatshirt', 'mode' => 'bulk_fixed', 'unit_rate' => 3.50, 'no_grading' => true],

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
                'cassette_player' => ['label' => 'Cassette Player', 'mode' => 'bulk_fixed', 'unit_rate' => 5.00, 'no_grading' => true],
                'cd_player' => ['label' => 'CD Player', 'mode' => 'bulk_fixed', 'unit_rate' => 6.00, 'no_grading' => true],
                'flatscreen_tv' => ['label' => 'Flatscreen TV', 'mode' => 'bulk_fixed', 'unit_rate' => 13.00, 'no_grading' => true],

                'mask' => ['label' => 'Mask', 'mode' => 'bulk_fixed', 'unit_rate' => 2.50, 'no_grading' => true],
                'furry_doll' => ['label' => 'Furry Doll', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
                'funko_pop' => ['label' => 'Funko Pop', 'mode' => 'bulk_fixed', 'unit_rate' => 1.50, 'no_grading' => true],
                'board_game' => ['label' => 'Board Game', 'mode' => 'bulk_fixed', 'unit_rate' => 2.00, 'no_grading' => true],
            ],
        ];
    }

    public function getItemTypesForDropdown()
    {
        $rules = $this->getRules();
        $output = [];
        foreach ($rules['item_types'] as $key => $config) {
            $output[$key] = $config['label'];
        }
        return $output;
    }

    public function getGradesForDropdown()
    {
        return array_keys($this->getRules()['grade_multipliers']);
    }

    public function calculate(array $lines, array $offerInputs = [])
    {
        $rules = $this->getRules();
        $normalized = [];
        $cashTotal = 0;

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

            $noGrading = !empty($cfg['no_grading']);
            $grade = $line['condition_grade'] ?? ($cfg['default_grade'] ?? 'VG+');
            $gradeMultiplier = $noGrading ? 1.0 : (float) ($rules['grade_multipliers'][$grade] ?? 1);
            $standardMultiplier = (float) ($line['standard_multiplier'] ?? $rules['individual_vinyl_standard_multiplier']);
            $discogsMedian = (float) ($line['discogs_median_price'] ?? 0);
            $unitRate = isset($cfg['unit_rate']) ? (float) $cfg['unit_rate'] : (float) ($line['unit_rate'] ?? 0);

            if ($cfg['mode'] === 'individual_discogs') {
                $cashLine = $quantity * $discogsMedian * $gradeMultiplier * $standardMultiplier;
            } else {
                $cashLine = $quantity * $unitRate * $gradeMultiplier;
            }

            $cashLine = round($cashLine, 2);
            $cashTotal += $cashLine;
            $creditLine = round($cashLine * (float) $rules['credit_bonus_multiplier'], 2);

            $normalized[] = [
                'line_order' => $i,
                'item_type' => $itemType,
                'title' => $line['title'] ?? null,
                'genre' => $line['genre'] ?? null,
                'condition_grade' => $noGrading ? null : $grade,
                'quantity' => $quantity,
                'discogs_median_price' => $discogsMedian > 0 ? $discogsMedian : null,
                'grade_multiplier' => $gradeMultiplier,
                'standard_multiplier' => $standardMultiplier,
                'unit_rate' => $unitRate > 0 ? $unitRate : null,
                'line_cash_total' => $cashLine,
                'line_credit_total' => $creditLine,
            ];
        }

        $cashTotal = round($cashTotal, 2);
        $creditTotal = round($cashTotal * (float) $rules['credit_bonus_multiplier'], 2);

        $result = [
            'lines' => $normalized,
            'collection_summary' => $this->summarizeCollection($normalized),
            'calculated_cash_total' => $cashTotal,
            'calculated_credit_total' => $creditTotal,
            'starting_offer_cash' => isset($offerInputs['starting_offer_cash']) && $offerInputs['starting_offer_cash'] !== '' ? (float) $offerInputs['starting_offer_cash'] : round($cashTotal * 0.50, 2),
            'starting_offer_credit' => isset($offerInputs['starting_offer_credit']) && $offerInputs['starting_offer_credit'] !== '' ? (float) $offerInputs['starting_offer_credit'] : round($creditTotal * 0.50, 2),
            'second_offer_cash' => isset($offerInputs['second_offer_cash']) && $offerInputs['second_offer_cash'] !== '' ? (float) $offerInputs['second_offer_cash'] : round($cashTotal * 0.75, 2),
            'second_offer_credit' => isset($offerInputs['second_offer_credit']) && $offerInputs['second_offer_credit'] !== '' ? (float) $offerInputs['second_offer_credit'] : round($creditTotal * 0.75, 2),
            'final_offer_cash' => isset($offerInputs['final_offer_cash']) && $offerInputs['final_offer_cash'] !== '' ? (float) $offerInputs['final_offer_cash'] : round($cashTotal * 0.95, 2),
            'final_offer_credit' => isset($offerInputs['final_offer_credit']) && $offerInputs['final_offer_credit'] !== '' ? (float) $offerInputs['final_offer_credit'] : round($creditTotal * 0.95, 2),
        ];

        return $result;
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

        $equipmentKeys = ['receiver', 'dvd_player', 'camcorder_digital', 'cassette_player', 'cd_player', 'flatscreen_tv'];
        $apparelKeys = ['hat_cap', 'hat_fitted', 'tshirt', 'tanktop', 'sweatshirt'];
        $printKeys = ['magazine', 'book', 'coffee_table_book'];
        $toyKeys = ['mask', 'furry_doll', 'funko_pop', 'board_game'];

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
            } elseif ($t === 'dvd_used' || $t === 'dvd_sealed' || $t === 'dvd_criterion_used') {
                $formats['dvd'] += $qty;
            } elseif (strpos($t, 'bluray_') === 0 || $t === '4k_used') {
                $formats['bluray'] += $qty;
            } elseif (strpos($t, 'vhs_') === 0) {
                $formats['vhs'] += $qty;
            } elseif (strpos($t, 'videogame_') === 0) {
                $formats['video_game'] += $qty;
            } elseif (in_array($t, $apparelKeys, true)) {
                $formats['apparel'] += $qty;
            } elseif (in_array($t, $printKeys, true)) {
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
