<?php

namespace App\Console\Commands;

use App\Services\MorningPullsNotifier;
use Illuminate\Console\Command;

/** Morning pull list per store, tagging the person on shift (MorningPullsNotifier). */
class MorningPullsPost extends Command
{
    protected $signature = 'pulls:morning-post {--dry-run}';
    protected $description = 'Post each store\'s morning pull list to Slack, tagging the morning person';

    public function handle()
    {
        foreach (MorningPullsNotifier::tick((bool) $this->option('dry-run')) as $store => $res) {
            $this->info("{$store}: {$res}");
        }
        return 0;
    }
}
