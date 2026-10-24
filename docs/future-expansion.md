# Future expansion

Status: design notes for after the POC. None of this is part of the current implementation.

The POC stays a single Laravel application with file sessions, no accounts, and no MySQL. The notes below describe how to add persistence, a REST API, and mobile clients later without replacing `ChatService` or `KimiService`.

## Preserve the service layer

The rule that keeps the expansion small:

| Layer | POC responsibility | Later change |
| --- | --- | --- |
| Inertia `ChatController` | HTTP and Inertia only | Stays for the web UI |
| Future API controller | Does not exist yet | New class, same service calls |
| `ChatService` | Transcript rules and calls to inference | Gains a store dependency when the database arrives |
| `KimiService` | vLLM HTTP call | Stays free of sessions, Eloquent, and Inertia |
| React pages | Presentation | Unchanged by a mobile client |

Phase 7 keeps transcript access in private methods on `ChatService`. When MySQL is introduced, those private methods move to a transcript store used by `ChatService`. Callers of `send()`, `transcript()`, and `clear()` stay stable. `KimiService::complete()` stays stable.

A second inference provider, if it is ever approved, is a new class beside `KimiService`. The POC does not add a provider interface in advance.

## Introducing MySQL

When the product needs durable history:

1. Add a MySQL connection through Laravel’s normal `DB_*` environment variables. Keep secrets in the environment.
2. Add migrations for the tables below. Do not reuse the POC session payload as a schema.
3. Point session and cache drivers at shared stores in that same change only if the deployment needs them. The inference path does not require shared sessions.
4. Implement the store and inject it into `ChatService`.
5. Keep file-session behavior behind a config flag until the web UI is switched, so the cutover is explicit.

Existing POC sessions are disposable. There is no migration of session files into MySQL.

### Conversations and messages

Proposed tables, created only in that future change:

`users`

- `id`
- `name`
- `email`
- `password`
- timestamps

The users table arrives with authentication. A conversation belongs to a user. The POC has no user row.

`conversations`

- `id`
- `user_id`, foreign key, indexed
- `title`, nullable string, filled from the first message or left blank
- timestamps

`messages`

- `id`
- `conversation_id`, foreign key, indexed
- `role`, string constrained to `user` or `assistant`
- `content`, text
- `latency_ms`, nullable integer, set on assistant rows
- `prompt_tokens`, nullable integer
- `completion_tokens`, nullable integer
- `error`, nullable string, set when a turn failed and no assistant text was stored
- timestamps

Index `(conversation_id, id)` for chronological reads. Content is UTF-8 so Arabic and English stay intact.

`ChatService` loads the conversation’s messages, applies the same outbound cap it uses in the POC, calls `KimiService`, and inserts the new rows in one database transaction. A failed inference records the error and does not insert a fake assistant reply. That matches the POC rule.

Authorization is part of the same change. Every query is scoped to the authenticated user’s conversations. A conversation id from the client is never trusted by itself.

## Introducing a Laravel REST API

Add routes in `routes/api.php` when a non-Inertia client exists. They call the same `ChatService`.

Sketch, not implemented:

| Method and path | Behavior |
| --- | --- |
| `GET /api/conversations` | List the current user’s conversations |
| `POST /api/conversations` | Create an empty conversation |
| `GET /api/conversations/{conversation}/messages` | Return stored messages |
| `POST /api/conversations/{conversation}/messages` | Validate `message`, call `ChatService`, return the new turn and latency |
| `DELETE /api/conversations/{conversation}` | Delete one conversation for the current user |

Validation stays in Form Requests. The API request shares rules with `StoreChatMessageRequest` so the 4,000 character limit and the required string rule are defined once.

Responses use one JSON shape:

```json
{
  "data": {
    "conversation_id": 1,
    "messages": [],
    "latency_ms": 840
  }
}
```

Errors use Laravel’s validation responses for bad input, `404` when the conversation is missing or belongs to someone else, and a stable application error when vLLM fails. The controller chooses the HTTP status. `KimiService` still throws the same inference exception the web UI already handles.

The Inertia routes remain for the browser. They stay in place alongside the API. A later decision can move the browser onto the API; the POC does not require that move.

## Mobile clients

A mobile application is another client of the REST API. It is not a new inference integration.

Implications:

- The mobile app sends text and renders the transcript. It never receives the vLLM base URL or the inference key.
- Authentication is required before conversations are stored per user. See below.
- The same message validation applies.
- Timeouts on the device should be at least as long as `KIMI_TIMEOUT`, so a slow completion is not aborted locally while Laravel is still waiting.
- Offline history is a client concern. The server remains the source of stored conversations once MySQL exists.
- Image upload stays out of scope until a future requirement adds it. The checkpoint can accept images at the inference engine. The product API does not grow an image field as a side effect.

No mobile framework is selected here.

## Authentication

The POC has none, on purpose. When the API stores user data, add Laravel Sanctum in that change:

- The first-party web UI can keep the session guard it already uses for CSRF.
- Mobile and other external clients use Sanctum personal access tokens, or the SPA cookie flow, chosen when those clients are specified.
- API routes that read or write conversations use `auth:sanctum`.
- `ChatService` receives the user id from the controller. It does not read the session guard itself, so the service remains usable from both entry points.
- Token abilities can wait until there is more than one client type to distinguish.

Sanctum is the default later choice because it is Laravel’s first-party API token package and the brief names it. It is not installed in the POC.

Authorization lives in the query scope and, if the project later adds policies, in a conversation policy. Hiding controls in a client is not the control.

## What stays unchanged

These POC decisions remain valid after the expansion:

- One Laravel application.
- `KimiService` as the only vLLM client.
- Environment variables for the base URL, model id, timeout, and key.
- Loopback vLLM plus a private network path. A public unauthenticated inference port stays unacceptable.
- The OpenAI-compatible request body.
- Arabic and English as ordinary Unicode text.

The expansion replaces how transcripts are stored and how clients authenticate. It does not replace how a completion is requested.
