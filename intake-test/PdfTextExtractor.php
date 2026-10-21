<?php

namespace App\Services;

/**
 * Minimal PDF text extractor in plain PHP: returns every text run with its
 * page position and width, the same shape pdf.js gives the browser
 * importers ({str, x, width, y}; y = page * 100000 - pdfY so rows sort top
 * to bottom across pages).
 *
 * Built for the distributor invoices (AMS and Alliance), which are simple
 * generated PDFs: Type0 / Identity-H fonts with a ToUnicode map, FlateDecode
 * content streams, no object streams. The server can't take Composer
 * packages or install poppler (deploy is git-only, no sudo), so this stays
 * dependency-free. Checked against pdf.js on real AMS and Alliance invoices
 * (same strings and positions).
 */
class PdfTextExtractor
{
    private $raw;
    private $objs = [];

    public function items(string $pdf): array
    {
        $this->raw = $pdf;
        $this->objs = [];
        $this->readObjects();

        $fonts = $this->fonts();
        $items = [];
        $page = 0;
        foreach ($this->pageContents() as $contentIds) {
            $page++;
            $stream = '';
            foreach ($contentIds as $cid) {
                $stream .= $this->decoded($cid) . "\n";
            }
            $this->runContent($stream, $page, $fonts, $items);
        }
        return $items;
    }

    private function readObjects()
    {
        $t = $this->raw;
        if (!preg_match_all('/(\d+)\s+0\s+obj\b/', $t, $m, PREG_OFFSET_CAPTURE)) return;
        $lastEnd = 0;
        foreach ($m[1] as $i => $hit) {
            $id = (int) $hit[0];
            $start = $m[0][$i][1];
            if ($start < $lastEnd) continue; // "N 0 obj" bytes inside a binary stream
            $end = strpos($t, 'endobj', $start);
            if ($end === false) $end = strlen($t);
            $body = substr($t, $start, $end - $start);
            $si = strpos($body, 'stream');
            $dict = $si !== false ? substr($body, 0, $si) : $body;
            $stream = null;
            if ($si !== false) {
                $ds = $start + $si + 6;
                if (($t[$ds] ?? '') === "\r") $ds++;
                if (($t[$ds] ?? '') === "\n") $ds++;
                if (preg_match('/\/Length\s+(\d+)(?!\s+0\s+R)/', $dict, $lm)) {
                    $len = (int) $lm[1];
                } else {
                    $es = strpos($t, 'endstream', $ds);
                    $len = ($es === false ? $end : $es) - $ds;
                }
                $stream = substr($t, $ds, $len);
                $end = max($end, $ds + $len);
            }
            $lastEnd = $end;
            $this->objs[$id] = ['dict' => $dict, 'stream' => $stream, 'dec' => null];
        }
    }

    private function decoded($id)
    {
        if (!isset($this->objs[$id]) || $this->objs[$id]['stream'] === null) return '';
        $o = &$this->objs[$id];
        if ($o['dec'] === null) {
            $o['dec'] = $o['stream'];
            if (strpos($o['dict'], 'FlateDecode') !== false) {
                $d = @gzuncompress($o['stream']);
                if ($d === false) $d = @gzinflate($o['stream']);
                if ($d === false) $d = @gzinflate(substr($o['stream'], 2));
                $o['dec'] = $d === false ? '' : $d;
            }
        }
        return $o['dec'];
    }

    // Page objects in document order -> their content stream ids.
    private function pageContents()
    {
        $pages = [];
        // Follow /Pages /Kids order when possible, else object order.
        $root = null;
        foreach ($this->objs as $id => $o) {
            if (preg_match('/\/Type\s*\/Catalog\b/', $o['dict']) && preg_match('/\/Pages\s+(\d+)\s+0\s+R/', $o['dict'], $m)) {
                $root = (int) $m[1];
                break;
            }
        }
        $order = [];
        $walk = function ($id) use (&$walk, &$order) {
            $d = $this->objs[$id]['dict'] ?? '';
            if (preg_match('/\/Type\s*\/Pages\b/', $d) && preg_match('/\/Kids\s*\[([^\]]*)\]/', $d, $k)) {
                preg_match_all('/(\d+)\s+0\s+R/', $k[1], $kids);
                foreach ($kids[1] as $kid) $walk((int) $kid);
            } elseif (preg_match('/\/Type\s*\/Page\b/', $d)) {
                $order[] = $id;
            }
        };
        if ($root) $walk($root);
        if (!$order) {
            foreach ($this->objs as $id => $o) {
                if (preg_match('/\/Type\s*\/Page\b/', $o['dict'])) $order[] = $id;
            }
        }
        foreach ($order as $pid) {
            $d = $this->objs[$pid]['dict'];
            if (preg_match('/\/Contents\s*\[([^\]]*)\]/', $d, $m)) {
                preg_match_all('/(\d+)\s+0\s+R/', $m[1], $ids);
                $pages[] = array_map('intval', $ids[1]);
            } elseif (preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $d, $m)) {
                $pages[] = [(int) $m[1]];
            }
        }
        return $pages;
    }

    // Resource name (/F1, /FoTF0 ...) => ['map' => cid => char, 'w' => cid => width, 'dw' => n, 'two' => bool]
    private function fonts()
    {
        $byObj = [];
        foreach ($this->objs as $id => $o) {
            $d = $o['dict'];
            if (!preg_match('/\/Type\s*\/Font\b/', $d) && !preg_match('/\/ToUnicode/', $d)) continue;
            $f = ['map' => [], 'w' => [], 'dw' => 1000, 'two' => strpos($d, 'Identity') !== false];
            if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $d, $m)) {
                $f['map'] = $this->cmap($this->decoded((int) $m[1]));
            }
            if (preg_match('/\/DescendantFonts\s*\[?\s*(\d+)\s+0\s+R/', $d, $m) && isset($this->objs[(int) $m[1]])) {
                $dd = $this->objs[(int) $m[1]]['dict'];
                if (preg_match('/\/W\s+(\d+)\s+0\s+R/', $dd, $wm) && isset($this->objs[(int) $wm[1]])) {
                    $f['w'] = $this->cidWidths(preg_replace('/^\s*\d+\s+0\s+obj/', '', $this->objs[(int) $wm[1]]['dict']));
                } elseif (preg_match('/\/W\s*\[/', $dd, $x, PREG_OFFSET_CAPTURE)) {
                    $f['w'] = $this->cidWidths($this->bracketed($dd, $x[0][1] + strlen($x[0][0]) - 1));
                }
                if (preg_match('/\/DW\s+(\d+)/', $dd, $dw)) $f['dw'] = (int) $dw[1];
            } elseif (preg_match('/\/FirstChar\s+(\d+)/', $d, $fc) && preg_match('/\/Widths\s*\[([^\]]*)\]/', $d, $wd)) {
                $first = (int) $fc[1];
                foreach (preg_split('/\s+/', trim($wd[1])) as $i => $w) {
                    if ($w !== '') $f['w'][$first + $i] = (float) $w;
                }
            }
            $byObj[$id] = $f;
        }
        $res = [];
        foreach ($this->objs as $o) {
            if (preg_match_all('/\/Font\s*<<(.*?)>>/s', $o['dict'], $fm)) {
                foreach ($fm[1] as $block) {
                    preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+(\d+)\s+0\s+R/', $block, $pairs, PREG_SET_ORDER);
                    foreach ($pairs as $p) {
                        if (isset($byObj[(int) $p[2]])) $res[$p[1]] = $byObj[(int) $p[2]];
                    }
                }
            }
        }
        return $res;
    }

    // Text of a balanced [...] starting at $pos (which points at '[').
    private function bracketed($s, $pos)
    {
        $depth = 0;
        for ($i = $pos, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '[') $depth++;
            elseif ($s[$i] === ']') { $depth--; if ($depth === 0) return substr($s, $pos, $i - $pos + 1); }
        }
        return substr($s, $pos);
    }

    // CID width array: c [w1 w2 ...] or c1 c2 w, repeated.
    private function cidWidths($txt)
    {
        $w = [];
        $txt = trim($txt);
        if ($txt !== '' && $txt[0] === '[') $txt = substr($txt, 1, -1);
        preg_match_all('/\[[^\]]*\]|-?\d+(?:\.\d+)?/', $txt, $m);
        $t = $m[0];
        for ($i = 0, $n = count($t); $i + 1 < $n;) {
            $c = (int) $t[$i];
            if ($t[$i + 1][0] === '[') {
                foreach (preg_split('/\s+/', trim(substr($t[$i + 1], 1, -1))) as $j => $v) {
                    if ($v !== '') $w[$c + $j] = (float) $v;
                }
                $i += 2;
            } else {
                if ($i + 2 >= $n) break;
                for ($k = $c, $c2 = (int) $t[$i + 1], $v = (float) $t[$i + 2]; $k <= $c2; $k++) $w[$k] = $v;
                $i += 3;
            }
        }
        return $w;
    }

    private function cmap($txt)
    {
        $map = [];
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $txt, $blocks)) {
            foreach ($blocks[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(\[[^\]]*\]|<[0-9A-Fa-f]+>)/', $blk, $lines, PREG_SET_ORDER);
                foreach ($lines as $ln) {
                    $a = hexdec($ln[1]); $z = hexdec($ln[2]);
                    if ($ln[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $ln[3], $arr);
                        foreach ($arr[1] as $i => $h) $map[$a + $i] = $this->hexToUtf8($h);
                    } else {
                        $base = hexdec(substr($ln[3], 1, -1));
                        for ($c = $a; $c <= $z; $c++) $map[$c] = $this->codepoint($base + $c - $a);
                    }
                }
            }
        }
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $txt, $blocks)) {
            foreach ($blocks[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $lines, PREG_SET_ORDER);
                foreach ($lines as $ln) $map[hexdec($ln[1])] = $this->hexToUtf8($ln[2]);
            }
        }
        return $map;
    }

    private function hexToUtf8($h)
    {
        $out = '';
        for ($i = 0; $i + 4 <= strlen($h); $i += 4) $out .= $this->codepoint(hexdec(substr($h, $i, 4)));
        if (strlen($h) === 2) $out = $this->codepoint(hexdec($h));
        return $out;
    }

    private function codepoint($cp)
    {
        if ($cp === 0xAD) return '-'; // soft hyphen the invoices use for "-"
        return mb_convert_encoding(pack('N', $cp), 'UTF-8', 'UCS-4BE');
    }

    private function runContent($s, $page, $fonts, &$items)
    {
        preg_match_all('/\[(?:[^\]\\\\]|\\\\.)*\]|<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\)|\/[A-Za-z0-9_.+\-]+|-?\d*\.?\d+|[A-Za-z*\'"]+/', $s, $m);
        $tm = [1, 0, 0, 1, 0, 0]; $lm = $tm; $font = null; $fs = 1.0; $tl = 0.0;
        $st = [];
        foreach ($m[0] as $tk) {
            if (!preg_match('/^[A-Za-z*\'"]+$/', $tk)) { $st[] = $tk; continue; }
            switch ($tk) {
                case 'BT': $tm = [1, 0, 0, 1, 0, 0]; $lm = $tm; break;
                case 'Td': case 'TD':
                    $y = (float) array_pop($st); $x = (float) array_pop($st);
                    if ($tk === 'TD') $tl = -$y;
                    $lm = [$lm[0], $lm[1], $lm[2], $lm[3], $lm[4] + $x * $lm[0] + $y * $lm[2], $lm[5] + $x * $lm[1] + $y * $lm[3]];
                    $tm = $lm; break;
                case 'Tm':
                    $lm = array_map('floatval', array_slice($st, -6)); $tm = $lm; break;
                case 'TL': $tl = (float) array_pop($st); break;
                case 'T*':
                    $lm = [$lm[0], $lm[1], $lm[2], $lm[3], $lm[4] - $tl * $lm[2], $lm[5] - $tl * $lm[3]]; $tm = $lm; break;
                case 'Tf':
                    $fs = (float) array_pop($st); $name = ltrim((string) array_pop($st), '/');
                    $font = $fonts[$name] ?? null; break;
                case 'Tj': case 'TJ': case "'":
                    if ($tk === "'") {
                        $lm = [$lm[0], $lm[1], $lm[2], $lm[3], $lm[4] - $tl * $lm[2], $lm[5] - $tl * $lm[3]]; $tm = $lm;
                    }
                    $arg = (string) array_pop($st);
                    list($str, $w) = $this->showText($arg, $font, $tk === 'TJ');
                    $adv = $w / 1000 * $fs;
                    $scale = sqrt($tm[0] * $tm[0] + $tm[1] * $tm[1]) ?: 1;
                    if (trim($str) !== '') {
                        $items[] = ['str' => $str, 'x' => $tm[4], 'width' => $adv * $scale, 'y' => $page * 100000 - $tm[5]];
                    }
                    $tm = [$tm[0], $tm[1], $tm[2], $tm[3], $tm[4] + $adv * $tm[0], $tm[5] + $adv * $tm[1]];
                    break;
            }
            $st = [];
        }
    }

    // Decode a Tj string / TJ array into [text, width in glyph units].
    private function showText($arg, $font, $isArray)
    {
        $map = $font['map'] ?? [];
        $widths = $font['w'] ?? [];
        $dw = $font['dw'] ?? 1000;
        $two = $font['two'] ?? true;
        $str = ''; $w = 0.0;
        preg_match_all('/<([0-9A-Fa-f\s]*)>|\(((?:\\\\.|[^\\\\)])*)\)|(-?\d+(?:\.\d+)?)/', $arg, $parts, PREG_SET_ORDER);
        foreach ($parts as $p) {
            if (isset($p[3]) && $p[3] !== '') { if ($isArray) $w -= (float) $p[3]; continue; }
            if ($p[1] !== '' || (isset($p[0][0]) && $p[0][0] === '<')) {
                $hex = preg_replace('/\s+/', '', $p[1]);
                $codes = [];
                $step = $two ? 4 : 2;
                for ($i = 0; $i + $step <= strlen($hex); $i += $step) $codes[] = hexdec(substr($hex, $i, $step));
            } else {
                $bytes = stripcslashes($p[2]);
                $codes = [];
                if ($two) { for ($i = 0; $i + 1 < strlen($bytes); $i += 2) $codes[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]); }
                else { for ($i = 0; $i < strlen($bytes); $i++) $codes[] = ord($bytes[$i]); }
            }
            foreach ($codes as $c) {
                $str .= $map[$c] ?? ($two ? '' : chr($c));
                $w += $widths[$c] ?? $dw;
            }
        }
        return [$str, $w];
    }
}
