import { formatLatency } from './format';
import Icon from './Icon';

export default function JobStatus({ pending, error, latencyMs, configured, copy }) {
    if (configured && !pending && !error) {
        return null;
    }

    const working = Boolean(pending) && !error;

    return (
        <div className={error ? 'notice notice-error' : 'notice'} role={error ? 'alert' : 'status'} aria-live="polite">
            <Icon name={working ? 'working' : 'alert'} className={working ? 'icon-spin' : ''} />
            <div>
                {!configured && !error ? <p>{copy.not_configured}</p> : null}
                {pending ? <p>{pending.message}</p> : null}
                {pending ? <p className="hint">{copy.close_warning}</p> : null}
                {error ? <p>{error}</p> : null}
                {error && latencyMs != null ? (
                    <p className="meta">
                        <span>
                            <Icon name="clock" />
                            {formatLatency(latencyMs, copy)}
                        </span>
                    </p>
                ) : null}
            </div>
        </div>
    );
}
