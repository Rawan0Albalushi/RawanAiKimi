<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Exceptions\ImageStoreException;
use App\Exceptions\InferenceException;
use App\Services\Documents\DocumentPlan;
use App\Services\Documents\DocumentUnderstandingService;
use App\Services\Documents\ExtractedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ChatService
{
    private const TRANSCRIPT = 'chat.transcript';

    private const PENDING = 'chat.pending';

    private const ERROR = 'chat.error';

    private const LATENCY = 'chat.latency_ms';

    private const USAGE = 'chat.usage';

    private const BLOCKED = 'chat.blocked';

    private const PLAN = 'chat.document_plan';

    public function __construct(
        private readonly KimiService $kimi,
        private readonly ChatImageStore $images,
        private readonly ChatPdfStore $pdfs,
        private readonly DocumentUnderstandingService $documents,
    ) {}

    public function send(
        string $message,
        ?UploadedFile $image = null,
        ?UploadedFile $pdf = null,
        ?string $documentId = null,
        ?string $intent = null,
    ): ChatTurnResult {
        if (session()->get(self::BLOCKED) === true) {
            $this->rememberError(__('chat.startup_blocked'));

            return $this->result();
        }

        if ($this->pending() !== null) {
            $this->rememberError(__('chat.in_progress'));

            return $this->result();
        }

        if ($pdf instanceof UploadedFile || (is_string($documentId) && $documentId !== '')) {
            if ($image instanceof UploadedFile) {
                $this->rememberError(__('chat.pdf_and_image'));

                return $this->result();
            }

            return $this->sendDocument($message, $pdf, $documentId, $intent);
        }

        if ($message === '' && $image instanceof UploadedFile) {
            $message = __('chat.image_default_prompt');
        }

        if ($message === '') {
            $this->rememberError(__('chat.message_required'));

            return $this->result();
        }

        $transcript = $this->transcript();
        $lastIndex = $transcript === [] ? null : array_key_last($transcript);
        $last = $lastIndex === null ? null : $transcript[$lastIndex];
        $lastAttachment = is_array($last) ? $this->imageAttachment($last) : null;
        $sameText = is_array($last)
            && ($last['role'] ?? null) === 'user'
            && ($last['content'] ?? null) === $message;

        try {
            if ($sameText && $image === null && $lastAttachment === null) {
                // The failed text is already the last turn.
            } elseif ($sameText && $image instanceof UploadedFile) {
                $stored = $this->images->store($image);

                if ($lastAttachment !== null) {
                    $this->images->delete($lastAttachment['id']);
                }

                $transcript[$lastIndex]['attachment'] = $stored;
                session()->put(self::TRANSCRIPT, $transcript);
            } else {
                $turn = [
                    'role' => 'user',
                    'content' => $message,
                    'at' => now()->toIso8601String(),
                ];

                if ($image instanceof UploadedFile) {
                    $turn['attachment'] = $this->images->store($image);
                }

                $transcript[] = $turn;
                session()->put(self::TRANSCRIPT, $transcript);
            }
        } catch (ImageStoreException $exception) {
            $this->rememberError($exception->userMessage);

            return $this->result();
        }

        session()->forget([self::ERROR, self::LATENCY, self::USAGE]);

        try {
            $job = $this->kimi->submit($this->outbound($transcript));
        } catch (ImageStoreException|InferenceException $exception) {
            $this->rememberError($exception->userMessage);

            return $this->result();
        }

        $status = $job->status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'IN_QUEUE';

        session()->put(self::PENDING, [
            'job_id' => $job->id,
            'submitted_at' => now()->valueOf(),
            'status' => $status,
            'message' => $this->statusMessage($status),
            'transport_failures' => 0,
        ]);

        return $this->result();
    }

    public function refresh(): ChatTurnResult
    {
        $pending = $this->pending();

        if ($pending === null) {
            return $this->result();
        }

        $budgetMs = (int) config('kimi.poll_budget_seconds') * 1000;

        if (now()->valueOf() - (int) $pending['submitted_at'] > $budgetMs) {
            return $this->expire($pending);
        }

        try {
            $update = $this->kimi->fetchStatus($pending['job_id']);
        } catch (InferenceException $exception) {
            return $this->failTransport($pending, $exception);
        }

        if (! $update->finished) {
            $pending['status'] = $update->status;
            $pending['message'] = $this->statusMessage($update->status);
            $pending['transport_failures'] = 0;
            session()->put(self::PENDING, $pending);

            return $this->result();
        }

        if ($update->successful && $update->completion instanceof KimiCompletion) {
            if (($pending['document'] ?? false) === true) {
                return $this->advanceDocument($pending, $update->completion);
            }

            return $this->complete($pending, $update->completion);
        }

        return $this->failJob($pending, $update);
    }

    public function cancel(): ChatTurnResult
    {
        $pending = $this->pending();

        if ($pending === null) {
            return $this->result();
        }

        if (! $this->tryCancel($pending['job_id'])) {
            $this->rememberError(__('chat.cancel_failed'));

            return $this->result();
        }

        $this->forgetPending();
        $this->forgetDocumentPlan();
        $this->rememberOutcome(__('chat.cancelled'), $this->elapsed($pending));

        return $this->result();
    }

    public function clear(): ChatTurnResult
    {
        $pending = $this->pending();

        if ($pending !== null && ! $this->tryCancel($pending['job_id'])) {
            $this->rememberError(__('chat.cancel_failed'));

            return $this->result();
        }

        $this->images->deleteSession();
        $this->pdfs->deleteSession();

        session()->forget([
            self::TRANSCRIPT,
            self::PENDING,
            self::ERROR,
            self::LATENCY,
            self::USAGE,
            self::BLOCKED,
            self::PLAN,
        ]);

        return $this->result();
    }

    public function state(): ChatTurnResult
    {
        return $this->result();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function expire(array $pending): ChatTurnResult
    {
        $cancelled = $this->tryCancel((string) $pending['job_id']);
        $this->forgetPending();
        $this->forgetDocumentPlan();
        $this->rememberOutcome(
            $cancelled ? __('chat.wait_ended') : __('chat.wait_ended_cancel_failed'),
            $this->elapsed($pending),
        );

        return $this->result();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function failTransport(array $pending, InferenceException $exception): ChatTurnResult
    {
        if (! $exception->transient) {
            $this->forgetPending();
            $this->forgetDocumentPlan();
            $this->rememberOutcome($exception->userMessage, $this->elapsed($pending));

            return $this->result();
        }

        $failures = ((int) ($pending['transport_failures'] ?? 0)) + 1;

        if ($failures >= (int) config('kimi.transport_attempts')) {
            $this->tryCancel((string) $pending['job_id']);
            $this->forgetPending();
            $this->forgetDocumentPlan();
            $this->rememberOutcome(__('chat.transport_gave_up'), $this->elapsed($pending));

            return $this->result();
        }

        $pending['transport_failures'] = $failures;
        $pending['message'] = __('chat.transport_retry');
        session()->put(self::PENDING, $pending);

        return $this->result();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function complete(array $pending, KimiCompletion $completion): ChatTurnResult
    {
        $latency = $this->elapsed($pending);
        $transcript = $this->transcript();
        $transcript[] = [
            'role' => 'assistant',
            'content' => $completion->content,
            'at' => now()->toIso8601String(),
            'latency_ms' => $latency,
            'usage' => $completion->usage,
            'model' => $completion->model,
        ];

        session()->put(self::TRANSCRIPT, $transcript);
        session()->put(self::LATENCY, $latency);
        session()->put(self::USAGE, $completion->usage);
        session()->forget(self::ERROR);
        $this->forgetPending();

        return $this->result();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function failJob(array $pending, JobUpdate $update): ChatTurnResult
    {
        $this->forgetPending();
        $this->forgetDocumentPlan();

        if ($update->errorType === 'startup_error') {
            session()->put(self::BLOCKED, true);
        }

        $message = $this->safeText($update->errorMessage) ?? match ($update->status) {
            'CANCELLED' => __('chat.cancelled'),
            'TIMED_OUT' => __('chat.timed_out'),
            default => __('chat.failed'),
        };

        if ($update->errorType === 'startup_error') {
            $message = __('chat.startup_blocked').' '.$message;
        }

        $this->rememberOutcome($message, $this->elapsed($pending));

        return $this->result();
    }

    private function tryCancel(string $jobId): bool
    {
        try {
            $this->kimi->cancel($jobId);

            return true;
        } catch (InferenceException) {
            return false;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array<int, array<string, mixed>>
     */
    private function outbound(array $transcript): array
    {
        $system = [
            'role' => 'system',
            'content' => $this->systemPrompt($transcript),
        ];
        $last = $transcript === [] ? null : $transcript[array_key_last($transcript)];

        if (is_array($last) && ($last['role'] ?? null) === 'user' && $this->imageAttachment($last) !== null) {
            return [$system, $this->imageMessage($last)];
        }

        $cap = max(1, (int) config('kimi.context_messages'));
        $messages = [$system];

        foreach (array_slice($transcript, -$cap) as $turn) {
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        return $messages;
    }

    /**
     * The image is sent only with the turn that uploaded it. Earlier turns
     * stay in the transcript and are omitted here so the 2,048-token context
     * can hold the image.
     *
     * @param  array<string, mixed>  $turn
     * @return array{role: string, content: array<int, array<string, mixed>>}
     */
    private function imageMessage(array $turn): array
    {
        $attachment = $this->imageAttachment($turn);

        if ($attachment === null) {
            throw new ImageStoreException(__('chat.image_missing'));
        }

        return [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $turn['content']],
                ['type' => 'image_url', 'image_url' => ['url' => $this->images->dataUrl($attachment['id'])]],
            ],
        ];
    }

    public function attachmentBytes(string $id): ?string
    {
        $known = false;

        foreach ($this->transcript() as $turn) {
            $attachment = $this->imageAttachment($turn);

            if ($attachment !== null && hash_equals($attachment['id'], $id)) {
                $known = true;
                break;
            }
        }

        if (! $known) {
            return null;
        }

        try {
            return $this->images->read($id);
        } catch (ImageStoreException) {
            return null;
        }
    }

    /**
     * The base prompt stays configurable. The language line is decided from
     * the latest user message so an Arabic question is not answered in English.
     *
     * @param  array<int, array<string, mixed>>  $transcript
     */
    private function systemPrompt(array $transcript): string
    {
        $instruction = $this->replyLanguage($transcript) === 'ar'
            ? 'The user wrote in Arabic. Reply entirely in Arabic. Do not answer in English.'
            : 'The user wrote in English. Reply entirely in English.';

        $base = trim((string) config('kimi.system_prompt'));

        return $base === '' ? $instruction : $base."\n\n".$instruction;
    }

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     */
    private function replyLanguage(array $transcript): string
    {
        $latest = '';

        foreach ($transcript as $turn) {
            if (($turn['role'] ?? null) === 'user' && is_string($turn['content'] ?? null)) {
                $latest = $turn['content'];
            }
        }

        if (preg_match('/\p{Arabic}/u', $latest) === 1) {
            return 'ar';
        }

        if (preg_match('/[A-Za-z]/', $latest) === 1) {
            return 'en';
        }

        return app()->getLocale() === 'ar' ? 'ar' : 'en';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transcript(): array
    {
        $rows = session()->get(self::TRANSCRIPT, []);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, function (mixed $row): bool {
            return is_array($row)
                && in_array($row['role'] ?? null, ['user', 'assistant'], true)
                && is_string($row['content'] ?? null);
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pending(): ?array
    {
        $pending = session()->get(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['job_id'] ?? null) || ! is_numeric($pending['submitted_at'] ?? null)) {
            return null;
        }

        return $pending;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicTranscript(): array
    {
        return array_map(function (array $turn): array {
            $public = [
                'role' => $turn['role'],
                'content' => $turn['content'],
                'at' => is_string($turn['at'] ?? null) ? $turn['at'] : null,
            ];

            if (isset($turn['latency_ms']) && is_numeric($turn['latency_ms'])) {
                $public['latencyMs'] = (int) $turn['latency_ms'];
            }

            if (isset($turn['usage']) && is_array($turn['usage'])) {
                $public['usage'] = $turn['usage'];
            }

            if (isset($turn['model']) && is_string($turn['model']) && $turn['model'] !== '') {
                $public['model'] = $turn['model'];
            }

            $pdf = $this->pdfAttachment($turn);

            if ($pdf !== null && $this->pdfs->exists($pdf['id'])) {
                $public['attachment'] = [
                    'id' => $pdf['id'],
                    'kind' => 'pdf',
                    'name' => $pdf['name'],
                    'bytes' => $pdf['bytes'],
                    'pages' => $pdf['pages'],
                ];
            }

            $attachment = $this->imageAttachment($turn);

            if ($attachment !== null && $this->images->exists($attachment['id'])) {
                $public['attachment'] = [
                    'id' => $attachment['id'],
                    'url' => route('chat.attachments.show', ['id' => $attachment['id']]),
                    'width' => $attachment['width'],
                    'height' => $attachment['height'],
                ];
            }

            return $public;
        }, $this->transcript());
    }

    /**
     * @return array{status: string, message: string}|null
     */
    private function publicPending(): ?array
    {
        $pending = $this->pending();

        if ($pending === null) {
            return null;
        }

        $status = is_string($pending['status'] ?? null) ? $pending['status'] : 'IN_QUEUE';

        return [
            'status' => $status,
            'message' => is_string($pending['message'] ?? null) ? $pending['message'] : $this->statusMessage($status),
        ];
    }

    private function result(): ChatTurnResult
    {
        $latency = session()->get(self::LATENCY);
        $usage = session()->get(self::USAGE);
        $error = session()->get(self::ERROR);

        return new ChatTurnResult(
            transcript: $this->publicTranscript(),
            pending: $this->publicPending(),
            error: is_string($error) ? $error : null,
            latencyMs: is_numeric($latency) ? (int) $latency : null,
            usage: is_array($usage) ? $usage : null,
            blocked: session()->get(self::BLOCKED) === true,
            configured: $this->kimi->isConfigured(),
        );
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function elapsed(array $pending): int
    {
        $started = is_numeric($pending['started_at'] ?? null)
            ? (int) $pending['started_at']
            : (int) $pending['submitted_at'];

        return max(0, now()->valueOf() - $started);
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'IN_PROGRESS' => __('chat.status_progress'),
            'IN_QUEUE' => __('chat.status_queue'),
            default => __('chat.status_queue'),
        };
    }

    private function rememberError(string $error): void
    {
        session()->put(self::ERROR, $error);
    }

    private function rememberOutcome(string $error, int $latencyMs): void
    {
        session()->put(self::ERROR, $this->safeText($error) ?? $error);
        session()->put(self::LATENCY, $latencyMs);
        session()->forget(self::USAGE);
    }

    private function forgetPending(): void
    {
        session()->forget(self::PENDING);
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return array{id: string, bytes: int, width: int, height: int}|null
     */
    private function imageAttachment(array $turn): ?array
    {
        $attachment = $turn['attachment'] ?? null;

        if (! is_array($attachment) || ($attachment['kind'] ?? null) === 'pdf') {
            return null;
        }

        $id = $attachment['id'] ?? null;
        $bytes = $attachment['bytes'] ?? null;
        $width = $attachment['width'] ?? null;
        $height = $attachment['height'] ?? null;

        if (! is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return null;
        }

        if (! is_numeric($bytes) || ! is_numeric($width) || ! is_numeric($height)) {
            return null;
        }

        $width = (int) $width;
        $height = (int) $height;
        $bytes = (int) $bytes;

        if ($width < 1 || $height < 1 || $bytes < 1) {
            return null;
        }

        return [
            'id' => $id,
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return array{id: string, name: string, bytes: int, pages: int}|null
     */
    private function pdfAttachment(array $turn): ?array
    {
        $attachment = $turn['attachment'] ?? null;

        if (! is_array($attachment) || ($attachment['kind'] ?? null) !== 'pdf') {
            return null;
        }

        $id = $attachment['id'] ?? null;
        $name = $attachment['name'] ?? null;
        $bytes = $attachment['bytes'] ?? null;
        $pages = $attachment['pages'] ?? null;

        if (! is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return null;
        }

        if (! is_string($name) || $name === '' || mb_strlen($name) > 120 || str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }

        if (! is_numeric($bytes) || ! is_numeric($pages)) {
            return null;
        }

        $bytes = (int) $bytes;
        $pages = (int) $pages;

        if ($bytes < 1 || $pages < 1) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'bytes' => $bytes,
            'pages' => $pages,
        ];
    }

    private function sendDocument(string $message, ?UploadedFile $pdf, ?string $documentId, ?string $intent): ChatTurnResult
    {
        $intent = $intent === 'summarize' ? 'summarize' : 'question';

        if (trim($message) === '' && $intent === 'summarize') {
            $message = __('chat.pdf_summarize_prompt');
        }

        if (trim($message) === '') {
            $this->rememberError(__('chat.message_required'));

            return $this->result();
        }

        try {
            if ($pdf instanceof UploadedFile) {
                $stored = $this->pdfs->store($pdf);
            } else {
                $existing = $this->pdfs->read(strtolower((string) $documentId));
                $stored = [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'bytes' => $existing->bytes,
                    'pages' => $existing->pageCount,
                ];
            }

            $extracted = $this->pdfs->read($stored['id']);
        } catch (DocumentException $exception) {
            $this->rememberError($exception->userMessage);

            return $this->result();
        }

        $this->rememberDocumentTurn($message, [
            'kind' => 'pdf',
            'id' => $stored['id'],
            'name' => $stored['name'],
            'bytes' => $stored['bytes'],
            'pages' => $stored['pages'],
        ]);

        try {
            $plan = $this->documents->plan($extracted, $intent, $message);
        } catch (DocumentException $exception) {
            $this->rememberError($exception->userMessage);

            return $this->result();
        }

        session()->forget([self::ERROR, self::LATENCY, self::USAGE]);

        if (is_string($plan->localReply) && $plan->localReply !== '') {
            $this->appendAssistant($plan->localReply);
            $this->forgetDocumentPlan();

            return $this->result();
        }

        return $this->submitDocumentStage($plan, $extracted, $plan->cursor, null);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function advanceDocument(array $pending, KimiCompletion $completion): ChatTurnResult
    {
        $plan = DocumentPlan::fromSession(is_array(session()->get(self::PLAN)) ? session()->get(self::PLAN) : []);

        if ($plan === null) {
            $this->forgetPending();
            $this->forgetDocumentPlan();
            $this->rememberOutcome(__('chat.pdf_interrupted'), $this->elapsed($pending));

            return $this->result();
        }

        try {
            $extracted = $this->pdfs->read($plan->documentId);
        } catch (DocumentException $exception) {
            $this->forgetPending();
            $this->forgetDocumentPlan();
            $this->rememberOutcome($exception->userMessage, $this->elapsed($pending));

            return $this->result();
        }

        $lastStage = $plan->cursor >= count($plan->stages) - 1;

        if ($lastStage) {
            $this->forgetPending();
            $this->forgetDocumentPlan();

            return $this->complete($pending, new KimiCompletion(
                content: $this->documents->finalize($plan, $completion->content),
                usage: $completion->usage,
                model: $completion->model,
                finishReason: $completion->finishReason,
            ));
        }

        $pages = $this->documents->stagePages($plan, $extracted, $plan->cursor);
        $next = $plan->withPartial(Str::limit(trim($completion->content), 6000, ''), $pages);
        $started = is_numeric($pending['started_at'] ?? null) ? (int) $pending['started_at'] : (int) $pending['submitted_at'];

        return $this->submitDocumentStage($next, $extracted, $next->cursor, $started);
    }

    private function submitDocumentStage(DocumentPlan $plan, ExtractedDocument $document, int $stageIndex, ?int $startedAt): ChatTurnResult
    {
        try {
            $job = $this->kimi->submit($this->documents->messages($plan, $document, $stageIndex));
        } catch (DocumentException|InferenceException $exception) {
            $this->forgetPending();
            $this->forgetDocumentPlan();
            $pages = $this->pagesAlreadyRead($plan);
            $message = $pages === []
                ? $exception->userMessage
                : __('chat.pdf_stopped', ['pages' => $this->documents->formatPages($pages)]);
            $this->rememberError($message);

            return $this->result();
        }

        $now = now()->valueOf();
        $total = count($plan->stages);
        $status = $job->status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'IN_QUEUE';

        session()->put(self::PLAN, $plan->toSession());
        session()->put(self::PENDING, [
            'job_id' => $job->id,
            'submitted_at' => $now,
            'started_at' => $startedAt ?? $now,
            'status' => $status,
            'message' => $total > 1
                ? __('chat.pdf_reading', ['current' => $stageIndex + 1, 'total' => $total])
                : $this->statusMessage($status),
            'transport_failures' => 0,
            'document' => true,
        ]);

        return $this->result();
    }

    /**
     * @param  array{kind: string, id: string, name: string, bytes: int, pages: int}  $attachment
     */
    private function rememberDocumentTurn(string $message, array $attachment): void
    {
        $transcript = $this->transcript();
        $lastIndex = $transcript === [] ? null : array_key_last($transcript);
        $last = $lastIndex === null ? null : $transcript[$lastIndex];
        $lastPdf = is_array($last) ? $this->pdfAttachment($last) : null;
        $sameText = is_array($last)
            && ($last['role'] ?? null) === 'user'
            && ($last['content'] ?? null) === $message
            && $lastPdf !== null
            && hash_equals($lastPdf['id'], $attachment['id']);

        if ($sameText) {
            return;
        }

        $transcript[] = [
            'role' => 'user',
            'content' => $message,
            'at' => now()->toIso8601String(),
            'attachment' => $attachment,
        ];
        session()->put(self::TRANSCRIPT, $transcript);
    }

    private function appendAssistant(string $content): void
    {
        $transcript = $this->transcript();
        $transcript[] = [
            'role' => 'assistant',
            'content' => $content,
            'at' => now()->toIso8601String(),
        ];
        session()->put(self::TRANSCRIPT, $transcript);
    }

    /**
     * @return array<int, int>
     */
    private function pagesAlreadyRead(DocumentPlan $plan): array
    {
        $pages = [];

        foreach ($plan->partials as $partial) {
            foreach ($partial['pages'] as $page) {
                $pages[] = $page;
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    private function forgetDocumentPlan(): void
    {
        session()->forget(self::PLAN);
    }

    private function safeText(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $clean = preg_replace('/Bearer\s+\S+/i', '[redacted]', $message);
        $clean = is_string($clean) ? $clean : $message;
        $clean = preg_replace('/data:image\\\\?\/[a-z0-9.+-]+;base64,[A-Za-z0-9+\/=\\\\]+/i', '[redacted]', $clean);

        return Str::limit(is_string($clean) ? $clean : $message, 300);
    }
}
