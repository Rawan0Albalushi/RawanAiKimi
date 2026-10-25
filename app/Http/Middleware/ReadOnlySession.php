<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

class ReadOnlySession
{
    /**
     * Attachment requests run beside polling. Saving the session there writes
     * an older copy and can erase the language or the in-progress reply.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $session = $request->session();
            $session->setHandler(new ReadonlySessionHandler($session->getHandler()));
        }

        return $next($request);
    }
}

final class ReadonlySessionHandler implements SessionHandlerInterface
{
    public function __construct(private readonly SessionHandlerInterface $handler) {}

    public function open(string $path, string $name): bool
    {
        return $this->handler->open($path, $name);
    }

    public function close(): bool
    {
        return $this->handler->close();
    }

    public function read(string $id): string|false
    {
        return $this->handler->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        return $this->handler->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->handler->gc($max_lifetime);
    }
}
