<?php

namespace App\Console\Commands;

use App\Services\ChatPdfStore;
use Illuminate\Console\Command;

class PruneChatDocuments extends Command
{
    protected $signature = 'chat-documents:prune';

    protected $description = 'Delete chat PDFs older than the retention window.';

    public function handle(ChatPdfStore $documents): int
    {
        $deleted = $documents->prune();

        $this->components->info("Deleted {$deleted} expired chat PDFs.");

        return self::SUCCESS;
    }
}
