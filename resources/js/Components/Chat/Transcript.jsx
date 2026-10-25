import { formatBytes, formatClock, formatLatency } from './format';
import Icon, { Mark } from './Icon';

function partLabel(copy, kind, pages) {
    const count = Number(pages);

    if (kind === 'xlsx') {
        return count === 1 ? copy.office_sheet : copy.office_sheets.replace(':count', String(count));
    }

    if (kind === 'docx') {
        return count === 1 ? copy.office_section : copy.office_sections.replace(':count', String(count));
    }

    return count === 1 ? copy.pdf_page : copy.pdf_pages.replace(':count', String(count));
}

export default function Transcript({ transcript, copy, locale, busy, onSummarize, onAsk }) {
    if (transcript.length === 0) {
        return (
            <div className="empty">
                <Icon name="message" />
                <p>{copy.empty}</p>
            </div>
        );
    }

    return transcript.map((message, index) => {
        const speaker = message.role === 'assistant' ? copy.assistant : copy.you;
        const clock = formatClock(message.at, locale);
        const assistant = message.role === 'assistant';

        return (
            <article className={assistant ? 'turn turn-assistant' : 'turn turn-user'} key={`${message.at ?? 'turn'}-${index}`}>
                <header>
                    <span className="speaker">
                        <span className="speaker-mark">{assistant ? <Mark /> : <Icon name="user" />}</span>
                        <span>{speaker}</span>
                    </span>
                    {clock ? <time dateTime={message.at}>{clock}</time> : null}
                </header>
                <p dir="auto">{message.content}</p>
                {['pdf', 'docx', 'xlsx'].includes(message.attachment?.kind) ? (
                    <div className="turn-pdf">
                        <div className="pdf-file">
                            <Icon name="document" />
                            <span className="pdf-copy">
                                <span className="pdf-name">{message.attachment.name}</span>
                                <span className="pdf-meta">
                                    {formatBytes(message.attachment.bytes, copy)}
                                    {' · '}
                                    {partLabel(copy, message.attachment.kind, message.attachment.pages)}
                                </span>
                            </span>
                        </div>
                        {message.role === 'user' ? (
                            <div className="pdf-actions">
                                <button type="button" className="button button-small" disabled={busy} onClick={() => onSummarize(message.attachment)}>
                                    {message.attachment.kind === 'xlsx'
                                        ? copy.office_summarize_sheet
                                        : message.attachment.kind === 'docx'
                                            ? copy.office_summarize_word
                                            : copy.pdf_summarize}
                                </button>
                                <button type="button" className="button button-small" disabled={busy} onClick={() => onAsk(message.attachment)}>
                                    {copy.pdf_ask}
                                </button>
                            </div>
                        ) : null}
                    </div>
                ) : null}
                {message.attachment?.url ? (
                    <img
                        className="turn-image"
                        src={message.attachment.url}
                        alt={copy.attached_image}
                        width={message.attachment.width || undefined}
                        height={message.attachment.height || undefined}
                    />
                ) : null}
                {assistant && (message.latencyMs != null || message.model || message.usage?.total_tokens != null) ? (
                    <p className="meta">
                        {message.latencyMs != null ? (
                            <span>
                                <Icon name="clock" />
                                {formatLatency(message.latencyMs, copy)}
                            </span>
                        ) : null}
                        {message.usage?.total_tokens != null ? (
                            <span>
                                <Icon name="tokens" />
                                {message.usage.total_tokens} {copy.tokens}
                            </span>
                        ) : null}
                        {message.model ? (
                            <span>
                                <Icon name="model" />
                                {message.model}
                            </span>
                        ) : null}
                    </p>
                ) : null}
            </article>
        );
    });
}
