import { useEffect, useLayoutEffect, useRef, useState } from 'react';
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
    const hasOffice = form.data.office instanceof File;
    const hasDocument = Boolean(activeDocument?.id) && !hasImage && !hasPdf && !hasOffice;
    const locked = form.processing || Boolean(pending) || blocked || !configured;
    const sendDisabled = locked || (form.data.message.trim() === '' && !hasImage);
    const canSummarize = !locked && (hasPdf || hasOffice || hasDocument);
    const attachedKind = hasOffice ? fileKind(form.data.office?.name) : (hasDocument ? activeDocument?.kind : 'pdf');
    const placeholder = hasPdf || hasOffice || hasDocument ? copy.pdf_ask_placeholder : copy.ask_placeholder;

    useEffect(() => {
        if (!didFocus.current) {
            inputRef.current?.focus();
            didFocus.current = true;
        }
    }, []);

    useLayoutEffect(() => {
        const field = inputRef.current;

        if (!field) {
            return;
        }

        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }, [form.data.message]);

    useEffect(() => () => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
    }, [previewUrl]);

    function submit(event, intent = 'question') {
        event.preventDefault();
        submitIntent.current = intent;

        const summarizing = intent === 'summarize' && (hasPdf || hasOffice || hasDocument);
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

            if (data.office instanceof File) {
                return {
                    message: text || (chosen === 'summarize' ? summarizePrompt(copy, fileKind(data.office.name)) : ''),
                    office: data.office,
                    intent: chosen,
                };
            }

            if (activeDocument?.id) {
                return {
                    message: text || (chosen === 'summarize' ? summarizePrompt(copy, activeDocument.kind) : ''),
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
                    const replacedDocument = form.data.pdf instanceof File || form.data.office instanceof File;

                    form.reset('message', 'image', 'pdf', 'office');
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

        form.setData({
            ...form.data,
            pdf: null,
            office: null,
        });
    }

    function chooseFile(file) {
        if (!file || locked) {
            return;
        }

        const kind = fileKind(file.name, file.type);
        const pdf = kind === 'pdf';
        const office = kind === 'docx' || kind === 'xlsx';
        const image = kind === 'image';

        if (!pdf && !office && !image) {
            setLocalError(copy.file_invalid);
            return;
        }

        if ((pdf || office) && file.size > maxPdfKilobytes * 1024) {
            setLocalError(copy.pdf_too_large.replace(':limit', String(Math.max(1, Math.round(maxPdfKilobytes / 1024)))));
            return;
        }

        if (image && file.size > maxImageKilobytes * 1024) {
            setLocalError(copy.image_too_large);
            return;
        }

        setLocalError('');
        onClearDocument();

        if (pdf || office) {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            setPreviewUrl(null);
            form.setData({
                ...form.data,
                image: null,
                pdf: pdf ? file : null,
                office: office ? file : null,
            });
        } else {
            setPreviewUrl(URL.createObjectURL(file));
            form.setData({
                ...form.data,
                image: file,
                pdf: null,
                office: null,
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

    const attachmentError = localError || form.errors.image || form.errors.pdf || form.errors.office || form.errors.document;
    const pdfFile = hasPdf ? form.data.pdf : null;
    const officeFile = hasOffice ? form.data.office : null;

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
                {pdfFile || officeFile ? (
                    <PdfDraft
                        copy={copy}
                        kind={pdfFile ? 'pdf' : fileKind(officeFile.name)}
                        name={(pdfFile || officeFile).name}
                        bytes={(pdfFile || officeFile).size}
                        status={form.processing ? copy.pdf_uploading : copy.pdf_ready}
                        onRemove={removePdf}
                        onSummarize={(event) => submit(event, 'summarize')}
                        summarizeDisabled={!canSummarize}
                    />
                ) : null}
                {hasDocument ? (
                    <PdfDraft
                        copy={copy}
                        kind={attachedKind}
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
                            accept="application/pdf,.pdf,.docx,.xlsx,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            tabIndex={-1}
                            onChange={onFileChange}
                            disabled={locked}
                        />
                        <span className="icon-host">
                            <button
                                type="button"
                                className="icon-button attach-image"
                                aria-label={`${copy.attach_image}. ${copy.image_hint}`}
                                disabled={locked}
                                onClick={() => fileRef.current?.click()}
                            >
                                <Icon name="image" />
                            </button>
                            <span className="icon-hint" aria-hidden="true">{copy.image_hint}</span>
                        </span>
                        <span className="icon-host">
                            <button
                                type="button"
                                className="icon-button attach-image"
                                aria-label={`${copy.attach_pdf}. ${copy.pdf_hint}`}
                                disabled={locked}
                                onClick={() => pdfRef.current?.click()}
                            >
                                <Icon name="document" />
                            </button>
                            <span className="icon-hint" aria-hidden="true">{copy.pdf_hint}</span>
                        </span>
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

function fileKind(name = '', type = '') {
    const lower = String(name).toLowerCase();

    if (lower.endsWith('.xlsx')) {
        return 'xlsx';
    }

    if (lower.endsWith('.docx')) {
        return 'docx';
    }

    if (type === 'application/pdf' || lower.endsWith('.pdf')) {
        return 'pdf';
    }

    if (ACCEPTED_TYPES.includes(type) || /\.(jpe?g|png|webp)$/.test(lower)) {
        return 'image';
    }

    return '';
}

function summarizePrompt(copy, kind) {
    if (kind === 'xlsx') {
        return copy.office_summarize_prompt_sheet;
    }

    if (kind === 'docx') {
        return copy.office_summarize_prompt_word;
    }

    return copy.pdf_summarize_prompt;
}

function partLabel(copy, kind, pages) {
    const count = Number(pages);

    if (!count) {
        return '';
    }

    if (kind === 'xlsx') {
        return count === 1 ? copy.office_sheet : copy.office_sheets.replace(':count', String(count));
    }

    if (kind === 'docx') {
        return count === 1 ? copy.office_section : copy.office_sections.replace(':count', String(count));
    }

    return count === 1 ? copy.pdf_page : copy.pdf_pages.replace(':count', String(count));
}

function PdfDraft({ copy, kind = 'pdf', name, bytes, pages, status, onRemove, onSummarize, summarizeDisabled }) {
    const pageLabel = partLabel(copy, kind, pages);
    const summarizeLabel = kind === 'xlsx'
        ? copy.office_summarize_sheet
        : kind === 'docx'
            ? copy.office_summarize_word
            : copy.pdf_summarize;

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
                    {summarizeLabel}
                </button>
            </div>
        </div>
    );
}
