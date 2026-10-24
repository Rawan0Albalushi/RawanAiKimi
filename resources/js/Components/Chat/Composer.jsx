import { useEffect, useRef, useState } from 'react';
import { formatBytes } from './format';
import Icon from './Icon';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export default function Composer({
    form,
    copy,
    modelName,
    maxCharacters,
    maxImageKilobytes,
    maxPdfKilobytes,
    activeDocument,
    pending,
    blocked,
    configured,
    onCancel,
    onClearDocument,
}) {
    const inputRef = useRef(null);
    const fileRef = useRef(null);
    const pdfRef = useRef(null);
    const didFocus = useRef(false);
    const submitIntent = useRef('question');
    const [previewUrl, setPreviewUrl] = useState(null);
    const [localError, setLocalError] = useState('');
    const [dropping, setDropping] = useState(false);
    const remaining = maxCharacters - form.data.message.length;
    const hasImage = form.data.image instanceof File;
    const hasPdf = form.data.pdf instanceof File;
    const hasDocument = Boolean(activeDocument?.id) && !hasImage && !hasPdf;
    const locked = form.processing || Boolean(pending) || blocked || !configured;
    const sendDisabled = locked || (form.data.message.trim() === '' && !hasImage);
    const canSummarize = !locked && (hasPdf || hasDocument);
    const placeholder = hasPdf || hasDocument ? copy.pdf_ask_placeholder : copy.ask_placeholder;

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

    function submit(event, intent = 'question') {
        event.preventDefault();
        submitIntent.current = intent;

        const summarizing = intent === 'summarize' && (hasPdf || hasDocument);
        const asking = form.data.message.trim() !== '' || hasImage;

        if (locked || (!summarizing && !asking)) {
            return;
        }

        form.transform((data) => {
            const text = data.message.trim();
            const chosen = submitIntent.current === 'summarize' ? 'summarize' : 'question';

            if (data.image instanceof File) {
                return {
                    message: text,
                    image: data.image,
                };
            }

            if (data.pdf instanceof File) {
                return {
                    message: text || (chosen === 'summarize' ? copy.pdf_summarize_prompt : ''),
                    pdf: data.pdf,
                    intent: chosen,
                };
            }

            if (activeDocument?.id) {
                return {
                    message: text || (chosen === 'summarize' ? copy.pdf_summarize_prompt : ''),
                    document: activeDocument.id,
                    intent: chosen,
                };
            }

            return { message: data.message };
        });
        form.post('/chat', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                if (!page.props.error) {
                    const replacedDocument = form.data.pdf instanceof File;

                    form.reset('message', 'image', 'pdf');
                    clearPreview();

                    if (replacedDocument) {
                        onClearDocument();
                    }
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

        if (pdfRef.current) {
            pdfRef.current.value = '';
        }
    }

    function removeImage() {
        clearPreview();
        form.setData('image', null);
    }

    function removePdf() {
        setLocalError('');

        if (pdfRef.current) {
            pdfRef.current.value = '';
        }

        form.setData('pdf', null);
    }

    function chooseFile(file) {
        if (!file || locked) {
            return;
        }

        const pdf = file.type === 'application/pdf' || (file.type === '' && file.name.toLowerCase().endsWith('.pdf'));
        const image = ACCEPTED_TYPES.includes(file.type);

        if (!pdf && !image) {
            setLocalError(copy.file_invalid);
            return;
        }

        if (pdf && file.size > maxPdfKilobytes * 1024) {
            setLocalError(copy.pdf_too_large.replace(':limit', String(Math.max(1, Math.round(maxPdfKilobytes / 1024)))));
            return;
        }

        if (image && file.size > maxImageKilobytes * 1024) {
            setLocalError(copy.image_too_large);
            return;
        }

        setLocalError('');
        onClearDocument();

        if (pdf) {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            setPreviewUrl(null);
            form.setData({
                ...form.data,
                image: null,
                pdf: file,
            });
        } else {
            setPreviewUrl(URL.createObjectURL(file));
            form.setData({
                ...form.data,
                image: file,
                pdf: null,
            });
        }

        if (fileRef.current) {
            fileRef.current.value = '';
        }

        if (pdfRef.current) {
            pdfRef.current.value = '';
        }
    }

    function onFileChange(event) {
        const files = Array.from(event.target.files ?? []);

        if (files.length > 1) {
            setLocalError(copy.file_one);
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
            setLocalError(copy.file_one);
            return;
        }

        chooseFile(files[0]);
    }

    const attachmentError = localError || form.errors.image || form.errors.pdf || form.errors.document;
    const pdfFile = hasPdf ? form.data.pdf : null;

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
                {pdfFile ? (
                    <PdfDraft
                        copy={copy}
                        name={pdfFile.name}
                        bytes={pdfFile.size}
                        status={form.processing ? copy.pdf_uploading : copy.pdf_ready}
                        onRemove={removePdf}
                        onSummarize={(event) => submit(event, 'summarize')}
                        summarizeDisabled={!canSummarize}
                    />
                ) : null}
                {hasDocument ? (
                    <PdfDraft
                        copy={copy}
                        name={activeDocument.name}
                        bytes={activeDocument.bytes}
                        pages={activeDocument.pages}
                        status={copy.pdf_ask}
                        onRemove={onClearDocument}
                        onSummarize={(event) => submit(event, 'summarize')}
                        summarizeDisabled={!canSummarize}
                    />
                ) : null}
                <textarea
                    id="message"
                    name="message"
                    ref={inputRef}
                    rows={2}
                    placeholder={placeholder}
                    maxLength={maxCharacters}
                    value={form.data.message}
                    onChange={(event) => form.setData('message', event.target.value)}
                    onKeyDown={onKeyDown}
                    aria-invalid={Boolean(form.errors.message || attachmentError)}
                    aria-describedby="message-hint"
                />
                {form.errors.message ? (
                    <p className="field-error" role="alert">
                        {form.errors.message}
                    </p>
                ) : null}
                {attachmentError ? (
                    <p className="field-error" role="alert">
                        {attachmentError}
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
                        <input
                            ref={pdfRef}
                            className="visually-hidden"
                            id="pdf"
                            name="pdf"
                            type="file"
                            accept="application/pdf,.pdf"
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
                        <button
                            type="button"
                            className="icon-button attach-image"
                            aria-label={copy.attach_pdf}
                            disabled={locked}
                            onClick={() => pdfRef.current?.click()}
                        >
                            <Icon name="document" />
                        </button>
                        <p className="chip">
                            <Icon name="image" />
                            <span>{copy.image_hint}</span>
                        </p>
                        <p className="chip">
                            <Icon name="document" />
                            <span>{copy.pdf_hint}</span>
                        </p>
                        <p className="chip chip-model">
                            <Icon name="model" />
                            <span>{modelName}</span>
                        </p>
                        <p id="message-hint" className="visually-hidden">
                            {copy.message_hint} {copy.drop_file}
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

function PdfDraft({ copy, name, bytes, pages, status, onRemove, onSummarize, summarizeDisabled }) {
    const pageLabel = Number(pages) === 1
        ? copy.pdf_page
        : Number(pages) > 1
            ? copy.pdf_pages.replace(':count', String(pages))
            : '';

    return (
        <div className="pdf-draft">
            <div className="pdf-file">
                <Icon name="document" />
                <span className="pdf-copy">
                    <span className="pdf-name">{name}</span>
                    <span className="pdf-meta">
                        {formatBytes(bytes, copy)}
                        {pageLabel ? ` · ${pageLabel}` : ''}
                        {status ? ` · ${status}` : ''}
                    </span>
                </span>
                <button type="button" className="icon-button attachment-remove" onClick={onRemove} aria-label={copy.remove_pdf}>
                    <Icon name="close" />
                </button>
            </div>
            <div className="pdf-actions">
                <button type="button" className="button button-small" onClick={onSummarize} disabled={summarizeDisabled}>
                    {copy.pdf_summarize}
                </button>
            </div>
        </div>
    );
}
