import { useEffect, useRef } from 'react';
import Icon from './Icon';

export default function Composer({
    form,
    copy,
    modelName,
    maxCharacters,
    pending,
    blocked,
    configured,
    onCancel,
}) {
    const inputRef = useRef(null);
    const didFocus = useRef(false);
    const remaining = maxCharacters - form.data.message.length;
    const sendDisabled = form.processing || Boolean(pending) || blocked || !configured || form.data.message.trim() === '';

    useEffect(() => {
        if (!didFocus.current) {
            inputRef.current?.focus();
            didFocus.current = true;
        }
    }, []);

    function submit(event) {
        event.preventDefault();

        if (sendDisabled) {
            return;
        }

        form.post('/chat', {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!page.props.error) {
                    form.reset('message');
                }
            },
        });
    }

    function onKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            submit(event);
        }
    }

    return (
        <form className="composer" id="composer" onSubmit={submit}>
            <label className="visually-hidden" htmlFor="message">{copy.message_label}</label>
            <div className="composer-box">
                <textarea
                    id="message"
                    name="message"
                    ref={inputRef}
                    rows={2}
                    placeholder={copy.ask_placeholder}
                    maxLength={maxCharacters}
                    value={form.data.message}
                    onChange={(event) => form.setData('message', event.target.value)}
                    onKeyDown={onKeyDown}
                    aria-invalid={Boolean(form.errors.message)}
                    aria-describedby="message-hint"
                />
                {form.errors.message ? (
                    <p className="field-error" role="alert">
                        {form.errors.message}
                    </p>
                ) : null}
                <div className="composer-foot">
                    <div className="composer-notes">
                        <p className="chip">
                            <Icon name="text" />
                            <span>{copy.text_only}</span>
                        </p>
                        <p className="chip chip-model">
                            <Icon name="model" />
                            <span>{modelName}</span>
                        </p>
                        <p id="message-hint" className="visually-hidden">
                            {copy.message_hint}
                        </p>
                        {remaining <= 600 ? (
                            <p className="hint">{copy.characters_left.replace(':count', String(remaining))}</p>
                        ) : null}
                    </div>
                    <div className="composer-actions">
                        {pending ? (
                            <button type="button" className="button button-quiet" onClick={onCancel}>
                                <Icon name="stop" />
                                <span className="button-label">{copy.cancel}</span>
                            </button>
                        ) : null}
                        <button type="submit" className="button button-primary" disabled={sendDisabled}>
                            <Icon name={form.processing ? 'working' : 'send'} className={form.processing ? 'icon-spin' : 'icon-forward'} />
                            <span>{form.processing ? copy.sending : copy.send}</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    );
}
