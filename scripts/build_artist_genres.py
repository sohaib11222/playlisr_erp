#!/usr/bin/env python3
"""Build app/Services/data/artist_genres.json from the store's artist/genre
Numbers spreadsheet.

The store maintains a curated `Artist -> Genre/Bin` list (more accurate than
Discogs' genres for how records are actually binned). This converts the latest
`.numbers` export into a committed JSON map that ArtistGenreLookup reads at
runtime.

Usage:
    pip3 install numbers-parser
    python3 scripts/build_artist_genres.py /path/to/record_store_artists_by_genre.numbers

Re-run whenever the spreadsheet is updated, then commit the regenerated JSON.

Key normalization MUST stay in sync with ArtistGenreLookup::normalizeArtist().
"""
import json
import re
import sys
from pathlib import Path

import numbers_parser

# Valid bins exactly as they appear in the spreadsheet's "Genre / Bin" column.
# Anything outside this set is reported and skipped so a typo in the sheet
# can't silently create a junk bin.
VALID_BINS = {
    "Rock", "Hip-Hop / Rap", "Alternative / Indie", "Metal", "R&B/Funk/Soul",
    "Pop", "Jazz", "Electronic / Dance", "Country", "Classical", "Latin",
    "Folk", "Blues", "Punk", "Reggae", "World", "New Wave", "Gospel",
    "Soundtrack",
}

OUT_PATH = Path(__file__).resolve().parent.parent / "app" / "Services" / "data" / "artist_genres.json"


def normalize_artist(raw) -> str:
    """Mirror of ArtistGenreLookup::normalizeArtist() in PHP."""
    # Numbers types bare-number names (112, 311, 702) as floats; coerce back.
    if isinstance(raw, float) and raw.is_integer():
        s = str(int(raw))
    else:
        s = str(raw)
    s = s.strip().casefold()
    s = re.sub(r"\s*\(\d+\)$", "", s)   # drop Discogs-style "(2)" disambiguator
    s = re.sub(r"\s+", " ", s)          # collapse internal whitespace
    return s.strip()


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__)
        return 2
    src = sys.argv[1]
    doc = numbers_parser.Document(src)
    table = doc.sheets[0].tables[0]
    rows = table.rows(values_only=True)

    mapping: dict[str, str] = {}
    skipped_bins: dict[str, int] = {}
    dupes: list[str] = []

    for artist, bin_label in rows[1:]:  # skip header
        if artist is None or bin_label is None:
            continue
        bin_label = str(bin_label).strip()
        if bin_label not in VALID_BINS:
            skipped_bins[bin_label] = skipped_bins.get(bin_label, 0) + 1
            continue
        key = normalize_artist(artist)
        if key == "":
            continue
        if key in mapping and mapping[key] != bin_label:
            dupes.append(f"{key!r}: {mapping[key]} -> {bin_label}")
        mapping[key] = bin_label

    mapping = dict(sorted(mapping.items()))
    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(json.dumps(mapping, ensure_ascii=False, indent=0) + "\n", encoding="utf-8")

    print(f"Wrote {len(mapping)} artists -> {OUT_PATH}")
    if dupes:
        print(f"\n{len(dupes)} duplicate artist(s) with conflicting bins (last wins):")
        for d in dupes:
            print(f"  {d}")
    if skipped_bins:
        print(f"\nSkipped rows with unrecognized bin labels:")
        for b, c in sorted(skipped_bins.items()):
            print(f"  {c:4}  {b!r}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
