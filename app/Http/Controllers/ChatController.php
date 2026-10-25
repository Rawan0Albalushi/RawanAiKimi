<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\StoreChatMessageRequest;
use App\Services\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function index(): Response
    {
        return $this->page();
    }

    public function store(StoreChatMessageRequest $request): RedirectResponse
    {
        $message = $request->validated('message');
        $image = $request->file('image');
        $pdf = $request->file('pdf');
        $office = $request->file('office');
        $document = $request->validated('document');
        $intent = $request->validated('intent');

        $this->chat->send(
            is_string($message) ? $message : '',
            $image instanceof UploadedFile ? $image : null,
            $pdf instanceof UploadedFile ? $pdf : null,
            is_string($document) ? strtolower($document) : null,
            is_string($intent) ? $intent : null,
            $office instanceof UploadedFile ? $office : null,
        );

        return redirect()->route('chat.index');
    }

    public function attachment(string $id): HttpResponse
    {
        $bytes = $this->chat->attachmentBytes(strtolower($id));

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function poll(): RedirectResponse
    {
        $this->chat->refresh();

        return redirect()->route('chat.index');
    }

    public function cancel(): RedirectResponse
    {
        $this->chat->cancel();

        return redirect()->route('chat.index');
    }

    public function clear(): RedirectResponse
    {
        $this->chat->clear();

        return redirect()->route('chat.index');
    }

    public function open(string $id): RedirectResponse
    {
        $this->chat->open($id);

        return redirect()->route('chat.index');
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->chat->destroy($id);

        return redirect()->route('chat.index');
    }

    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'in:en,ar'],
        ]);

        $request->session()->put('locale', $data['locale']);
        Cookie::queue(cookie(SetLocale::COOKIE, $data['locale'], SetLocale::MINUTES, null, null, null, false));

        return redirect()->route('chat.index');
    }

    private function page(): Response
    {
        App::setLocale(SetLocale::preferred(request()));

        return Inertia::render('Chat/Index', [
            ...$this->chat->state()->toArray(),
            'copy' => Lang::get('chat'),
            'pollIntervalMs' => (int) config('kimi.poll_interval_ms'),
            'maxCharacters' => 4000,
            'maxImageKilobytes' => (int) config('kimi.image_max_kilobytes'),
            'maxPdfKilobytes' => (int) config('kimi.document_max_kilobytes'),
            'modelName' => (string) config('kimi.model'),
        ]);
    }
}
