<?php

namespace App\Console\Commands;

use App\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Add the "top tier" missing subcategories (Sarah 2026-10-28) and re-file
 * items that obviously belong in them.
 *
 * Context: a scan of ~26k live product names found big groups with no
 * subcategory of their own — Christmas cassettes spread across Rock/Jazz/none, graded slabs mixed into
 * Pokemon Cards, National Geographic / Nintendo Power lumped into
 * "Pop Culture", 500 comics in one "Comic Books" bucket, anime figures with
 * no subcategory.
 *
 * Safe by design:
 *   - Dry-run default; --commit to write. Dry run lists every move.
 *   - A rule only moves items out of the subcategories it names (catch-alls
 *     and blanks), so correctly filed items stay put.
 *   - Comics that match both Marvel and DC words are left alone.
 *   - Idempotent: subcategories are created once; rerunning moves nothing new.
 *   - Moves bump updated_at so the website sync picks them up.
 *
 * Usage:
 *   php artisan nivessa:add-top-tier-subcategories
 *   php artisan nivessa:add-top-tier-subcategories --commit
 *   php artisan nivessa:add-top-tier-subcategories --create-only --commit
 */
class AddTopTierSubcategories extends Command
{
    protected $signature = 'nivessa:add-top-tier-subcategories
                            {--business=1 : business_id to scope to}
                            {--commit : Actually write (default: dry-run)}
                            {--create-only : Create the subcategories but move nothing}
                            {--show=40 : Max example moves to print per rule}';

    protected $description = 'Create the top-tier missing subcategories and re-file matching items.';

    // Vinyl and CD (used + sealed) keep their own genre lists: no new subs
    // and no moves there (Sarah 2026-10-29). handle() also refuses them.
    const MUSIC_ALL = ['Cassettes - Used', 'Cassettes - Sealed'];

    /** Parent category => new subcategories under it. */
    public static function newSubs()
    {
        return [
            'Cassettes - Used' => ['Christmas / Holiday'],
            'Cassettes - Sealed' => ['Christmas / Holiday'],
            'Toys' => ['Anime Figures'],
            'Trading Cards' => ['Graded Slabs'],
            'Magazines' => ['National Geographic', 'Music Magazines', 'Gaming Magazines'],
            'Books' => ['Marvel Comics', 'DC Comics', 'Indie & Image Comics'],
        ];
    }

    /**
     * Re-file rules. Each rule: parents it applies to, the subcategories it
     * may take items FROM (null = no subcategory), the target subcategory,
     * a regex on "name artist" and an optional exclude regex.
     */
    public static function rules()
    {
        $marvel = 'marvel|spider-?\s?man|\bhulk\b|x-?men|x-?force|wolverine|avengers|iron man|\bthor\b|captain america|deadpool|punisher|fantastic four|daredevil|venom|carnage|black panther|wakanda|moon knight|ghost rider|doctor strange|guardians of the galaxy|silver surfer|galactus|conan|deathlok|werewolf by night|what if|amadeus cho|elektra|ant-man|namor|new mutants|web of spider|she-hulk|killmonger|thanos|kang\b';
        $dc = '\bdc\b|batman|superman|wonder woman|justice league|aquaman|\bflash\b|green lantern|green arrow|\barrow\b|nightwing|joker|harley quinn|catwoman|teen titans|outsiders|world\'?s finest|supergirl|swamp thing|sandman|constantine|hellblazer|shazam|doom patrol|watchmen|the authority|lobo\b|darkseid';
        $indie = '\bimage\b|spawn|invincible|walking dead|\bsaga\b|\bidw\b|dark horse|boom!? studios|cyber ?force|turok|valiant|godzilla|ninja turtles|\btmnt\b|hellboy|savage dragon|witchblade|the darkness|youngblood|wildc\.?a\.?t\.?s|bone\b|archie|vertigo';

        return [
            [
                'label' => 'Christmas / Holiday',
                'parents' => self::MUSIC_ALL,
                'from' => '*',
                'to' => 'Christmas / Holiday',
                'match' => 'christmas|x-?mas\b|navidad|hanukk?ah|chanukah|happy holidays|holiday (songs|album|music|favou?rites|classics|greetings|cheer|collection|wishes|season|sing|spirit|hits|magic)|santa claus|jingle bell|sleigh ride|silent night|yuletide',
                'exclude' => 'billie holiday|david christmas',
            ],
            [
                'label' => 'Anime Figures',
                'parents' => ['Toys'],
                'from' => [null, 'Toy', 'Action Figures'],
                'to' => 'Anime Figures',
                'match' => 'anime|haikyu|naruto|one piece|demon slayer|jujutsu|dragon ?ball|inuyasha|sesshomaru|hololive|okayu|danganronpa|komaeda|kakegurui|yumeko|rwby|xiao long|sailor moon|my hero academia|chainsaw man|spy ?x ?family|attack on titan|evangelion|sword art|re:? ?zero|jojo|bleach|hunter ?x ?hunter|gundam|banpresto|nendoroid|figma|good smile|prize figure|tokyo ghoul|bocchi|frieren|hatsune|miku|genshin',
                'exclude' => 'funko|\bpop!?\s*#|#\s*\d',
            ],
            [
                'label' => 'Graded Slabs (Pokemon -> Pokemon Slabs)',
                'parents' => ['Trading Cards'],
                'from' => '*',
                'to' => 'Pokemon Slabs',
                'match' => '\b(psa|cgc|bgs|sgc|beckett)\b|\bgraded\b',
                'require' => 'pok[eé]mon|pikachu|charizard|eevee|mewtwo|psyduck',
                'require_or_from' => ['Pokemon Cards', 'Pokemon 1st Edition'],
                'skip_from' => ['Pokemon Slabs', 'Graded Slabs'],
            ],
            [
                'label' => 'Graded Slabs (everything else)',
                'parents' => ['Trading Cards'],
                'from' => '*',
                'to' => 'Graded Slabs',
                'match' => '\b(psa|cgc|bgs|sgc|beckett)\b|\bgraded\b',
                'exclude' => 'pok[eé]mon|pikachu|charizard|eevee|mewtwo|psyduck',
                'skip_from' => ['Pokemon Slabs', 'Graded Slabs', 'Pokemon Cards'],
            ],
            [
                'label' => 'National Geographic',
                'parents' => ['Magazines'],
                'from' => [null, 'Pop Culture', 'Magazines (New, Vintage)'],
                'to' => 'National Geographic',
                'match' => 'national geographic|\bnat\.? ?geo\b',
            ],
            [
                'label' => 'Rolling Stone (existing)',
                'parents' => ['Magazines'],
                'from' => [null, 'Pop Culture', 'Magazines (New, Vintage)'],
                'to' => 'Rolling Stone',
                'match' => 'rolling ?stone',
                'exclude' => 'rolling stones\b',
            ],
            [
                'label' => 'Music Magazines',
                'parents' => ['Magazines'],
                'from' => [null, 'Pop Culture', 'Magazines (New, Vintage)'],
                'to' => 'Music Magazines',
                'match' => '\bspin\b|alternative press|^ap\b|kerrang|\bmojo\b|\buncut\b|\bnme\b|billboard|\bcreem\b|guitar world|guitar player|revolver|decibel|\bvibe\b|the source|\bxxl\b|downbeat|down beat|relix|magnet magazine|hit parader|circus magazine|metal hammer|trouser press|musician|blender|tiger beat|\b16 magazine|teen beat|right on!?|jet magazine',
            ],
            [
                'label' => 'Gaming Magazines',
                'parents' => ['Magazines'],
                'from' => [null, 'Pop Culture', 'Magazines (New, Vintage)'],
                'to' => 'Gaming Magazines',
                'match' => 'nintendo power|game ?informer|\begm\b|electronic gaming|gamepro|pc gamer|playstation|xbox|tips ?& ?tricks|computer gaming|sega visions|gamefan|nintendo',
            ],
            [
                'label' => 'Marvel Comics',
                'parents' => ['Books'],
                'from' => ['Comic Books'],
                'to' => 'Marvel Comics',
                'match' => $marvel,
                'exclude' => $dc,
            ],
            [
                'label' => 'DC Comics',
                'parents' => ['Books'],
                'from' => ['Comic Books'],
                'to' => 'DC Comics',
                'match' => $dc,
                'exclude' => $marvel,
            ],
            [
                'label' => 'Indie & Image Comics',
                'parents' => ['Books'],
                'from' => ['Comic Books'],
                'to' => 'Indie & Image Comics',
                'match' => $indie,
                'exclude' => $marvel . '|' . $dc,
            ],
        ];
    }

    public static function isOffLimits($parent)
    {
        return (bool) preg_match('~vinyl|\bcds?\b~i', $parent);
    }

    /** Does a rule take this product? Pure, so it can be tested without a DB. */
    public static function ruleMatches(array $rule, $currentSub, $text)
    {
        $from = $rule['from'];
        if ($from !== '*') {
            $ok = false;
            foreach ($from as $f) {
                if ($f === null ? ($currentSub === null || $currentSub === '') : (strcasecmp((string) $currentSub, $f) === 0)) { $ok = true; break; }
            }
            if (!$ok) return false;
        }
        foreach (array_merge([$rule['to']], $rule['skip_from'] ?? []) as $skip) {
            if ($currentSub !== null && strcasecmp($currentSub, $skip) === 0) return false;
        }
        $t = mb_strtolower($text);
        if (!preg_match('~' . $rule['match'] . '~iu', $t)) return false;
        if (!empty($rule['require']) && !preg_match('~' . $rule['require'] . '~iu', $t)) {
            $inFrom = false;
            foreach ($rule['require_or_from'] ?? [] as $f) {
                if ($currentSub !== null && strcasecmp($currentSub, $f) === 0) $inFrom = true;
            }
            if (!$inFrom) return false;
        }
        if (!empty($rule['exclude']) && preg_match('~' . $rule['exclude'] . '~iu', $t)) return false;
        return true;
    }

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $show = (int) $this->option('show');

        $cats = Category::where('business_id', $businessId)->where('category_type', 'product')->get();
        $parentId = [];   // lower(name) => id
        $subId = [];      // parentId => [lower(name) => id]
        $subName = [];    // id => name
        foreach ($cats as $c) {
            if ((int) $c->parent_id === 0) $parentId[mb_strtolower(trim($c->name))] = $c->id;
            else { $subId[$c->parent_id][mb_strtolower(trim($c->name))] = $c->id; $subName[$c->id] = $c->name; }
        }

        // Step 1 — create the subcategories.
        $this->info('Step 1 — subcategories');
        foreach (self::newSubs() as $parent => $subs) {
            if (self::isOffLimits($parent)) { $this->warn("  ! {$parent} is off-limits — skipped"); continue; }
            $pid = $parentId[mb_strtolower($parent)] ?? null;
            if (!$pid) { $this->warn("  ! parent '{$parent}' not found — skipped"); continue; }
            foreach ($subs as $s) {
                if (isset($subId[$pid][mb_strtolower($s)])) { $this->line("  = {$parent} > {$s} (exists, id {$subId[$pid][mb_strtolower($s)]})"); continue; }
                if ($commit) {
                    $c = Category::create([
                        'business_id' => $businessId,
                        'name' => $s,
                        'category_type' => 'product',
                        'parent_id' => $pid,
                        'created_by' => 1,
                    ]);
                    $subId[$pid][mb_strtolower($s)] = $c->id;
                    $subName[$c->id] = $s;
                    $this->line("  + created {$parent} > {$s} (id {$c->id})");
                } else {
                    $this->line("  · [dry-run] would create {$parent} > {$s}");
                }
            }
        }
        if ($this->option('create-only')) { $this->info($commit ? 'Done (create only).' : 'DRY RUN (create only).'); return 0; }

        // Step 2 — re-file.
        $this->line('');
        $this->info('Step 2 — re-file matching items');
        $moved = [];  // product id => true, so two rules never fight over one item
        $total = 0;
        foreach (self::rules() as $rule) {
            $count = 0; $from = []; $examples = [];
            foreach ($rule['parents'] as $parent) {
                if (self::isOffLimits($parent)) continue;
                $pid = $parentId[mb_strtolower($parent)] ?? null;
                if (!$pid) continue;
                $targetId = $subId[$pid][mb_strtolower($rule['to'])] ?? null;
                DB::table('products')->where('business_id', $businessId)->where('category_id', $pid)
                    ->select('id', 'name', 'artist', 'sub_category_id')->orderBy('id')
                    ->chunk(2000, function ($rows) use ($rule, $parent, $pid, $targetId, $commit, $subName, &$moved, &$count, &$from, &$examples, $show) {
                        $ids = [];
                        foreach ($rows as $r) {
                            if (isset($moved[$r->id])) continue;
                            $cur = $r->sub_category_id ? ($subName[$r->sub_category_id] ?? null) : null;
                            if ($r->sub_category_id && $cur === null) continue; // sub belongs elsewhere; leave it
                            if (!self::ruleMatches($rule, $cur, $r->name . ' ' . $r->artist)) continue;
                            $moved[$r->id] = true;
                            $count++;
                            $k = $parent . ' > ' . ($cur ?: '(none)');
                            $from[$k] = ($from[$k] ?? 0) + 1;
                            if (count($examples) < $show) $examples[] = "#{$r->id} {$r->name}" . ($r->artist ? " — {$r->artist}" : '') . "  [{$k}]";
                            $ids[] = $r->id;
                        }
                        if ($commit && $targetId && $ids) {
                            DB::table('products')->whereIn('id', $ids)->update(['sub_category_id' => $targetId, 'updated_at' => now()]);
                        }
                    });
            }
            $total += $count;
            arsort($from);
            $this->line('');
            $this->line("▶ {$rule['label']}: " . ($commit ? 'moved' : 'would move') . " {$count}");
            foreach ($from as $k => $n) $this->line("    from {$k}: {$n}");
            foreach ($examples as $e) $this->line("    · {$e}");
        }

        $this->line('');
        $this->info(($commit ? '✅ Moved ' : '🧪 DRY RUN — would move ') . $total . ' items.' . ($commit ? '' : ' Re-run with --commit.'));
        return 0;
    }
}
