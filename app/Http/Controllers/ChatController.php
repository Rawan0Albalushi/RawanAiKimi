<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChatMessageRequest;
use App\Services\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (is_string($message)) {
            $this->chat->send($message);
        }

        return redirect()->route('chat.index');
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

    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'in:en,ar'],
        ]);

        $request->session()->put('locale', $data['locale']);

        return redirect()->route('chat.index');
    }

    private function page(): Response
    {
        return Inertia::render('Chat/Index', [
            ...$this->chat->state()->toArray(),
            'copy' => Lang::get('chat'),
            'pollIntervalMs' => (int) config('kimi.poll_interval_ms'),
            'maxCharacters' => 4000,
            'modelName' => (string) config('kimi.model'),
        ]);
    }
}
