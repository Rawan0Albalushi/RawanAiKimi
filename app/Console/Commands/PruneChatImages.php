<?php

namespace App\Console\Commands;

use App\Services\ChatImageStore;
use Illuminate\Console\Command;

class PruneChatImages extends Command
{
    protected $signature = 'chat-images:prune';

    protected $description = 'Delete chat images older than the retention window.';

    public function handle(ChatImageStore $images): int
    {
        $deleted = $images->prune();

        $this->components->info("Deleted {$deleted} expired chat images.");

        return self::SUCCESS;
    }
}
