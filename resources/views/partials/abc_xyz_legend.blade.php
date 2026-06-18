{{--
    Plain-language legend for the ABC / XYZ / ABC-XYZ classification, shown at
    the top of every page that uses these codes (import, markdown report, ICA).
    Self-contained styles scoped under .abcxyz-legend so it reads the same on
    both AdminLTE pages and the pos-v2 (abc-v2) report.
--}}
<style>
.abcxyz-legend { border:1px solid #DFD2B3; border-radius:12px; background:#FFFDF6; padding:16px 18px; margin-bottom:18px; color:#1F1B16; font-size:14px; line-height:1.5; }
.abcxyz-legend h3 { margin:0 0 4px; font-size:16px; font-weight:700; }
.abcxyz-legend .abcxyz-intro { color:#5A5045; margin:0 0 14px; }
.abcxyz-legend .abcxyz-cols { display:flex; flex-wrap:wrap; gap:22px; }
.abcxyz-legend .abcxyz-col { flex:1 1 280px; min-width:260px; }
.abcxyz-legend .abcxyz-col h4 { margin:0 0 6px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#5A5045; }
.abcxyz-legend ul { margin:0; padding-left:0; list-style:none; }
.abcxyz-legend li { margin:0 0 5px; }
.abcxyz-legend .code { display:inline-block; min-width:24px; text-align:center; font-weight:800; border-radius:6px; padding:1px 7px; margin-right:6px; border:1px solid transparent; }
.abcxyz-legend .code.A { background:#E5F0E8; border-color:#2F6B3E; color:#2F6B3E; }
.abcxyz-legend .code.B { background:#FFF9DB; border-color:#E8CF68; color:#5A4410; }
.abcxyz-legend .code.C { background:#F6E3DF; border-color:#7A1F1F; color:#7A1F1F; }
.abcxyz-legend .code.X, .abcxyz-legend .code.Y, .abcxyz-legend .code.Z { background:#EDEAF6; border-color:#5B4B9A; color:#5B4B9A; }
.abcxyz-legend table.abcxyz-matrix { border-collapse:separate; border-spacing:0; margin-top:2px; font-size:12.5px; }
.abcxyz-legend table.abcxyz-matrix th, .abcxyz-legend table.abcxyz-matrix td { border:1px solid #ECE3CF; padding:5px 8px; text-align:center; }
.abcxyz-legend table.abcxyz-matrix th { background:#F7F1E3; color:#5A5045; font-weight:700; }
.abcxyz-legend table.abcxyz-matrix td .combo { font-weight:800; }
.abcxyz-legend table.abcxyz-matrix td small { display:block; color:#8E8273; font-size:10.5px; }
</style>
<div class="abcxyz-legend">
    <h3>What ABC, XYZ and ABC-XYZ mean</h3>
    <p class="abcxyz-intro">Every product gets two letters from its sales history. <strong>ABC</strong> ranks how much it sells (value); <strong>XYZ</strong> ranks how steady that selling is (predictability). Combined, they tell you what to protect, reorder, or clear out.</p>
    <div class="abcxyz-cols">
        <div class="abcxyz-col">
            <h4>ABC — how much it sells</h4>
            <ul>
                <li><span class="code A">A</span> Best sellers — roughly the top 80% of sales. Keep stocked, full price.</li>
                <li><span class="code B">B</span> Steady middle — the next ~15% of sales. Hold price, reorder as needed.</li>
                <li><span class="code C">C</span> Slow movers — the bottom ~5%. Markdown candidates; clear the shelf.</li>
            </ul>
        </div>
        <div class="abcxyz-col">
            <h4>XYZ — how steady the demand is</h4>
            <ul>
                <li><span class="code X">X</span> Steady, predictable — sells at a regular pace. Easy to forecast and reorder.</li>
                <li><span class="code Y">Y</span> Variable / seasonal — demand swings (e.g. holidays, hype). Forecast with care.</li>
                <li><span class="code Z">Z</span> Sporadic — sells rarely and erratically. Hard to predict; avoid overstocking.</li>
            </ul>
        </div>
        <div class="abcxyz-col">
            <h4>ABC-XYZ — the two together</h4>
            <table class="abcxyz-matrix">
                <thead>
                    <tr><th></th><th>X (steady)</th><th>Y (variable)</th><th>Z (sporadic)</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th>A</th>
                        <td><span class="combo">AX</span><small>protect stock</small></td>
                        <td><span class="combo">AY</span><small>plan for swings</small></td>
                        <td><span class="combo">AZ</span><small>watch closely</small></td>
                    </tr>
                    <tr>
                        <th>B</th>
                        <td><span class="combo">BX</span><small>reorder steadily</small></td>
                        <td><span class="combo">BY</span><small>reorder with care</small></td>
                        <td><span class="combo">BZ</span><small>keep thin</small></td>
                    </tr>
                    <tr>
                        <th>C</th>
                        <td><span class="combo">CX</span><small>low but steady</small></td>
                        <td><span class="combo">CY</span><small>review</small></td>
                        <td><span class="combo">CZ</span><small>clear out, don't reorder</small></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
