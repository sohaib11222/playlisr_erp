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

                'cassette_hh_metal_punk' => ['label' => 'Cassette: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
                'cassette_rb_jazz_rock' => ['label' => 'Cassette: R&B/Jazz/Rock', 'mode' => 'bulk_fixed', 'unit_rate' => 0.50],
                'cassette_everything_else' => ['label' => 'Cassette: Everything Else', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],

                'rpm45_hh_metal_punk' => ['label' => '45 RPM: Hip-Hop/Metal/Punk', 'mode' => 'bulk_fixed', 'unit_rate' => 1.00],
                'rpm45_newwave_rock_alt' => ['label' => '45 RPM: New Wave/Rock/Alt', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                'rpm45_without_sleeve' => ['label' => '45 RPM: Without Sleeve', 'mode' => 'bulk_fixed', 'unit_rate' => 0.05],
                'rpm45_anything_else' => ['label' => '45 RPM: Anything Else', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],

                'dvd_used' => ['label' => 'DVD Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.15],
                'dvd_sealed' => ['label' => 'DVD Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.40],
                'bluray_used' => ['label' => 'Blu-ray Used', 'mode' => 'bulk_fixed', 'unit_rate' => 0.25],
                'bluray_sealed' => ['label' => 'Blu-ray Sealed', 'mode' => 'bulk_fixed', 'unit_rate' => 0.75],
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

            $grade = $line['condition_grade'] ?? ($cfg['default_grade'] ?? 'VG+');
            $gradeMultiplier = (float) ($rules['grade_multipliers'][$grade] ?? 1);
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

            $normalized[] = [
                'line_order' => $i,
                'item_type' => $itemType,
                'title' => $line['title'] ?? null,
                'genre' => $line['genre'] ?? null,
                'condition_grade' => $grade,
                'quantity' => $quantity,
                'discogs_median_price' => $discogsMedian > 0 ? $discogsMedian : null,
                'grade_multiplier' => $gradeMultiplier,
                'standard_multiplier' => $standardMultiplier,
                'unit_rate' => $unitRate > 0 ? $unitRate : null,
                'line_cash_total' => $cashLine,
            ];
        }

        $cashTotal = round($cashTotal, 2);
        $creditTotal = round($cashTotal * (float) $rules['credit_bonus_multiplier'], 2);

        $result = [
            'lines' => $normalized,
            'calculated_cash_total' => $cashTotal,
            'calculated_credit_total' => $creditTotal,
            'starting_offer_cash' => (float) ($offerInputs['starting_offer_cash'] ?? $cashTotal),
            'starting_offer_credit' => (float) ($offerInputs['starting_offer_credit'] ?? $creditTotal),
            'second_offer_cash' => (float) ($offerInputs['second_offer_cash'] ?? $cashTotal),
            'second_offer_credit' => (float) ($offerInputs['second_offer_credit'] ?? $creditTotal),
            'final_offer_cash' => (float) ($offerInputs['final_offer_cash'] ?? $cashTotal),
            'final_offer_credit' => (float) ($offerInputs['final_offer_credit'] ?? $creditTotal),
        ];

        return $result;
    }
}

