import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Composer from '../../Components/Chat/Composer';
import { formatWhen } from '../../Components/Chat/format';
import { Home } from '../../Components/Chat/Home';
import Icon, { Mark } from '../../Components/Chat/Icon';
import JobStatus from '../../Components/Chat/JobStatus';
import Transcript from '../../Components/Chat/Transcript';

export default function Index({
    transcript,
    pending,
    error,
    latencyMs,
    blocked,
    configured,
    pollIntervalMs,
    maxCharacters,
    maxImageKilobytes,
    maxPdfKilobytes,
    modelName,
    copy,
    chats = [],
    activeId = null,
}) {
    const { locale } = usePage().props;
    const form = useForm({ message: '', image: null, pdf: null, office: null });
    const [activeDocument, setActiveDocument] = useState(null);
    const endRef = useRef(null);
    const polling = useRef(false);
    const [navOpen, setNavOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const empty = transcript.length === 0;
    const current = transcript.find((message) => message.role === 'user') ?? transcript[0];
    const currentTitle = excerpt(current?.content);

    useEffect(() => {
        const next = locale === 'ar' ? 'ar' : 'en';
        document.documentElement.lang = next;
        document.documentElement.dir = next === 'ar' ? 'rtl' : 'ltr';

        if (locale === 'ar' || locale === 'en') {
            document.cookie = `locale=${locale}; Max-Age=34560000; Path=/; SameSite=Lax`;
        }
    }, [locale]);

    useEffect(() => {
        if (transcript.length === 0) {
            return;
        }

        const stage = endRef.current?.closest('.stage');

        if (stage) {
            stage.scrollTop = stage.scrollHeight;
        }
    }, [transcript.length, pending?.status, error]);

    useEffect(() => {
        if (!pending) {
            return undefined;
        }

        let stopped = false;

        const tick = () => {
            if (stopped || polling.current) {
                return;
            }

            polling.current = true;
            router.post('/chat/poll', {}, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                showProgress: false,
                onFinish: () => {
                    polling.current = false;
                },
            });
        };

        tick();
        const intervalMs = Math.max(2000, Number(pollIntervalMs) || 2000);
        const id = window.setInterval(tick, intervalMs);

        return () => {
            stopped = true;
            window.clearInterval(id);
        };
    }, [pending?.status, pollIntervalMs]);

    function setLocale(next) {
        if (next === locale) {
            return;
        }

        router.post('/locale', { locale: next }, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function clearChat() {
        setNavOpen(false);
        setActiveDocument(null);
        router.post('/chat/clear', {}, { preserveScroll: true });
    }

    function openChat(id) {
        if (!id || id === activeId || pending) {
            return;
        }

        setNavOpen(false);
        setActiveDocument(null);
        router.post(`/chat/${id}/open`, {}, { preserveScroll: true });
    }

    function deleteChat(id) {
        if (!id || !window.confirm(copy.delete_confirm)) {
            return;
        }

        setNavOpen(false);

        if (id === activeId) {
            setActiveDocument(null);
        }

        router.post(`/chat/${id}/delete`, {}, { preserveScroll: true });
    }

    function cancelReply() {
        router.post('/chat/cancel', {}, { preserveScroll: true, preserveState: true });
    }

    function summarizeDocument(attachment) {
        if (!attachment?.id || pending || blocked || !configured) {
            return;
        }

        const prompt = attachment.kind === 'xlsx'
            ? copy.office_summarize_prompt_sheet
            : attachment.kind === 'docx'
                ? copy.office_summarize_prompt_word
                : copy.pdf_summarize_prompt;

        router.post('/chat', {
            message: prompt,
            document: attachment.id,
            intent: 'summarize',
        }, { preserveScroll: true });
    }

    function askDocument(attachment) {
        if (!attachment?.id) {
            return;
        }

        setActiveDocument({
            id: attachment.id,
            kind: attachment.kind,
            name: attachment.name,
            bytes: attachment.bytes,
            pages: attachment.pages,
        });
        form.setData('image', null);
        form.setData('pdf', null);
        form.setData('office', null);
        window.requestAnimationFrame(() => {
            document.getElementById('message')?.focus();
        });
    }

    function insertPrompt(text) {
        form.setData('message', text);
        window.requestAnimationFrame(() => {
            document.getElementById('message')?.focus();
        });
    }

    function toggleNav() {
        if (window.matchMedia('(max-width: 860px)').matches) {
            setNavOpen((open) => !open);
            return;
        }

        setCollapsed((value) => !value);
    }

    const shellClass = ['shell', collapsed ? 'nav-collapsed' : '', navOpen ? 'nav-open' : ''].filter(Boolean).join(' ');

    return (
        <>
            <Head title={copy.app_name} />
            <a className="skip" href="#composer">
                {copy.skip}
            </a>
            <div className={shellClass}>
                <aside className="sidebar">
                    <div className="brand-row">
                        <Mark />
                        <div className="brand-copy">
                            <p className="display brand-name">{copy.app_name}</p>
                            <p>{copy.assistant_label}</p>
                        </div>
                        <button
                            type="button"
                            className="icon-button sidebar-toggle"
                            aria-expanded={navOpen || !collapsed}
                            aria-label={navOpen || !collapsed ? copy.collapse_sidebar : copy.expand_sidebar}
                            onClick={toggleNav}
                        >
                            <Icon name="chevron" className="icon-collapse" />
                        </button>
                    </div>
                    <button type="button" className="new-chat" onClick={clearChat}>
                        <Icon name="message" />
                        <span>{copy.new_chat}</span>
                    </button>
                    {chats.length > 0 ? (
                        <div className="recent">
                            <p className="recent-label">{copy.recent_chats}</p>
                            <ul className="recent-list">
                                {chats.map((chat) => {
                                    const title = excerpt(chat.title);
                                    const when = formatWhen(chat.updatedAt, locale);
                                    const active = chat.id === activeId;

                                    return (
                                        <li key={chat.id} className={active ? 'recent-row is-active' : 'recent-row'}>
                                            <button
                                                type="button"
                                                className="recent-open"
                                                aria-current={active ? 'true' : undefined}
                                                disabled={active || Boolean(pending)}
                                                onClick={() => openChat(chat.id)}
                                            >
                                                <Icon name="message" />
                                                <span>
                                                    <span className="recent-title">{title}</span>
                                                    {when ? <span className="recent-when">{when}</span> : null}
                                                </span>
                                            </button>
                                            <button
                                                type="button"
                                                className="recent-delete"
                                                aria-label={copy.delete_chat}
                                                onClick={() => deleteChat(chat.id)}
                                            >
                                                <Icon name="clear" />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ) : null}
                    <div className="sidebar-foot">
                        <Mark />
                        <div className="foot-copy">
                            <p>{copy.app_name}</p>
                            <p>{copy.powered_by.replace(':model', modelName)}</p>
                        </div>
                    </div>
                </aside>
                {navOpen ? (
                    <button type="button" className="backdrop" aria-label={copy.close_menu} onClick={() => setNavOpen(false)} />
                ) : null}
                <div className="stage">
                    <header className="topbar">
                        <button type="button" className="icon-button nav-toggle" aria-expanded={navOpen} aria-label={navOpen ? copy.close_menu : copy.open_menu} onClick={toggleNav}>
                            <Icon name="menu" />
                        </button>
                        {currentTitle ? <p className="top-title">{currentTitle}</p> : null}
                        <div className="locales" role="group" aria-label={copy.language}>
                            <button type="button" aria-pressed={locale === 'en'} onClick={() => setLocale('en')}>
                                <Icon name="globe" />
                                <span>{copy.english}</span>
                            </button>
                            <button type="button" aria-pressed={locale === 'ar'} onClick={() => setLocale('ar')}>
                                <span>{copy.arabic}</span>
                            </button>
                        </div>
                    </header>
                    <div className={empty ? 'home' : 'thread'} aria-busy={pending ? 'true' : 'false'}>
                        {empty ? <Home copy={copy} onInsert={insertPrompt} /> : (
                            <Transcript
                                transcript={transcript}
                                copy={copy}
                                locale={locale}
                                busy={Boolean(pending) || blocked || !configured}
                                onSummarize={summarizeDocument}
                                onAsk={askDocument}
                            />
                        )}
                        {!empty ? <h1 className="visually-hidden">{copy.app_name}</h1> : null}
                        <div ref={endRef} />
                    </div>
                    <div className="dock">
                        <JobStatus
                            pending={pending}
                            error={error}
                            latencyMs={latencyMs}
                            configured={configured}
                            copy={copy}
                        />
                        <Composer
                            form={form}
                            copy={copy}
                            modelName={modelName}
                            maxCharacters={maxCharacters}
                            maxImageKilobytes={maxImageKilobytes}
                            maxPdfKilobytes={maxPdfKilobytes}
                            activeDocument={activeDocument}
                            pending={pending}
                            blocked={blocked}
                            configured={configured}
                            onCancel={cancelReply}
                            onClearDocument={() => setActiveDocument(null)}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

function excerpt(value) {
    if (!value) {
        return '';
    }

    const text = value.replace(/\s+/g, ' ').trim();

    if (text.length <= 42) {
        return text;
    }

    return `${text.slice(0, 42)}…`;
}
