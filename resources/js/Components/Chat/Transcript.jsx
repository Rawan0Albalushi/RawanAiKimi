import { formatBytes, formatClock, formatLatency } from './format';
import Icon, { Mark } from './Icon';

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
                {message.attachment?.kind === 'pdf' ? (
                    <div className="turn-pdf">
                        <div className="pdf-file">
                            <Icon name="document" />
                            <span className="pdf-copy">
                                <span className="pdf-name">{message.attachment.name}</span>
                                <span className="pdf-meta">
                                    {formatBytes(message.attachment.bytes, copy)}
                                    {' · '}
                                    {message.attachment.pages === 1
                                        ? copy.pdf_page
                                        : copy.pdf_pages.replace(':count', String(message.attachment.pages))}
                                </span>
                            </span>
                        </div>
                        {message.role === 'user' ? (
                            <div className="pdf-actions">
                                <button type="button" className="button button-small" disabled={busy} onClick={() => onSummarize(message.attachment)}>
                                    {copy.pdf_summarize}
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
