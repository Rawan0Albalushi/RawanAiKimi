<?php

namespace App\Console\Commands;

use App\Services\ChatOfficeStore;
use App\Services\ChatPdfStore;
use Illuminate\Console\Command;

class PruneChatDocuments extends Command
{
    protected $signature = 'chat-documents:prune';

    protected $description = 'Delete chat documents older than the retention window.';

    public function handle(ChatPdfStore $pdfs, ChatOfficeStore $office): int
    {
        $deleted = $pdfs->prune() + $office->prune();

        $this->components->info("Deleted {$deleted} expired chat documents.");

        return self::SUCCESS;
    }
}
