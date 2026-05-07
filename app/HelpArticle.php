<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class HelpArticle extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'page_keys' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($q)
    {
        return $q->where('is_published', true);
    }

    public static function findByPageKey($key)
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        $prefix = explode('.', $key)[0];

        $candidates = static::published()
            ->whereNotNull('page_keys')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $exact = null;
        $prefixHit = null;
        foreach ($candidates as $c) {
            $keys = is_array($c->page_keys) ? $c->page_keys : [];
            if (in_array($key, $keys, true)) {
                $exact = $c;
                break;
            }
            if (!$prefixHit && in_array($prefix, $keys, true)) {
                $prefixHit = $c;
            }
        }

        return $exact ?: $prefixHit;
    }

    public function scopeSearch($q, $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        return $q->where(function ($qq) use ($like) {
            $qq->where('title', 'like', $like)
               ->orWhere('summary', 'like', $like)
               ->orWhere('body_html', 'like', $like)
               ->orWhere('section', 'like', $like);
        });
    }
}
