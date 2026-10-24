import { formatClock, formatLatency } from './format';
import Icon, { Mark } from './Icon';

export default function Transcript({ transcript, copy, locale }) {
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
