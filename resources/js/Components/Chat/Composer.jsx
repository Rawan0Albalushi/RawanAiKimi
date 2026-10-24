import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export default function Composer({
    form,
    copy,
    modelName,
    maxCharacters,
    maxImageKilobytes,
    pending,
    blocked,
    configured,
    onCancel,
}) {
    const inputRef = useRef(null);
    const fileRef = useRef(null);
    const didFocus = useRef(false);
    const [previewUrl, setPreviewUrl] = useState(null);
    const [localError, setLocalError] = useState('');
    const [dropping, setDropping] = useState(false);
    const remaining = maxCharacters - form.data.message.length;
    const hasImage = form.data.image instanceof File;
    const locked = form.processing || Boolean(pending) || blocked || !configured;
    const sendDisabled = locked || (form.data.message.trim() === '' && !hasImage);

    useEffect(() => {
        if (!didFocus.current) {
            inputRef.current?.focus();
            didFocus.current = true;
        }
    }, []);

    useEffect(() => () => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
    }, [previewUrl]);

    function submit(event) {
        event.preventDefault();

        if (sendDisabled) {
            return;
        }

        form.transform((data) => ({
            message: data.message,
            ...(data.image instanceof File ? { image: data.image } : {}),
        }));
        form.post('/chat', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                if (!page.props.error) {
                    form.reset('message', 'image');
                    clearPreview();
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

    function clearPreview() {
        setPreviewUrl(null);
        setLocalError('');

        if (fileRef.current) {
            fileRef.current.value = '';
        }
    }

    function removeImage() {
        clearPreview();
        form.setData('image', null);
    }

    function chooseFile(file) {
        if (!file || locked) {
            return;
        }

        if (!ACCEPTED_TYPES.includes(file.type)) {
            setLocalError(copy.image_invalid);
            return;
        }

        if (file.size > maxImageKilobytes * 1024) {
            setLocalError(copy.image_too_large);
            return;
        }

        setLocalError('');
        setPreviewUrl(URL.createObjectURL(file));
        form.setData('image', file);

        if (fileRef.current) {
            fileRef.current.value = '';
        }
    }

    function onFileChange(event) {
        const files = Array.from(event.target.files ?? []);

        if (files.length > 1) {
            setLocalError(copy.image_one);
            event.target.value = '';
            return;
        }

        chooseFile(files[0]);
    }

    function onDragOver(event) {
        event.preventDefault();

        if (!locked) {
            setDropping(true);
        }
    }

    function onDragLeave(event) {
        if (!event.currentTarget.contains(event.relatedTarget)) {
            setDropping(false);
        }
    }

    function onDrop(event) {
        event.preventDefault();
        setDropping(false);

        if (locked) {
            return;
        }

        const files = Array.from(event.dataTransfer.files ?? []);

        if (files.length !== 1) {
            setLocalError(copy.image_one);
            return;
        }

        chooseFile(files[0]);
    }

    const imageError = localError || form.errors.image;

    return (
        <form className="composer" id="composer" onSubmit={submit}>
            <label className="visually-hidden" htmlFor="message">{copy.message_label}</label>
            <div
                className={dropping ? 'composer-box is-dropping' : 'composer-box'}
                onDragOver={onDragOver}
                onDragLeave={onDragLeave}
                onDrop={onDrop}
            >
                {previewUrl ? (
                    <div className="attachment-row">
                        <img src={previewUrl} alt={copy.attached_image} width="72" height="72" />
                        <button type="button" className="icon-button attachment-remove" onClick={removeImage} aria-label={copy.remove_image}>
                            <Icon name="close" />
                        </button>
                    </div>
                ) : null}
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
                    aria-invalid={Boolean(form.errors.message || imageError)}
                    aria-describedby="message-hint"
                />
                {form.errors.message ? (
                    <p className="field-error" role="alert">
                        {form.errors.message}
                    </p>
                ) : null}
                {imageError ? (
                    <p className="field-error" role="alert">
                        {imageError}
                    </p>
                ) : null}
                <div className="composer-foot">
                    <div className="composer-notes">
                        <input
                            ref={fileRef}
                            className="visually-hidden"
                            id="image"
                            name="image"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            tabIndex={-1}
                            onChange={onFileChange}
                            disabled={locked}
                        />
                        <button
                            type="button"
                            className="icon-button attach-image"
                            aria-label={copy.attach_image}
                            disabled={locked}
                            onClick={() => fileRef.current?.click()}
                        >
                            <Icon name="image" />
                        </button>
                        <p className="chip">
                            <Icon name="image" />
                            <span>{copy.image_hint}</span>
                        </p>
                        <p className="chip chip-model">
                            <Icon name="model" />
                            <span>{modelName}</span>
                        </p>
                        <p id="message-hint" className="visually-hidden">
                            {copy.message_hint} {copy.drop_image}
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
