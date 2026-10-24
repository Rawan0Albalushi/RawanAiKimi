export default function Icon({ name, className = '' }) {
    return (
        <svg
            className={className ? `icon ${className}` : 'icon'}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            {glyphs[name]}
        </svg>
    );
}

export function Mark({ className = '' }) {
    return (
        <svg className={className ? `mark ${className}` : 'mark'} viewBox="0 0 32 32" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M6 6.5h16.5a2 2 0 0 1 2 2v9.2a2 2 0 0 1-2 2H13l-5.2 4.3a.8.8 0 0 1-1.3-.6V8.5a2 2 0 0 1 2-2Z" />
        </svg>
    );
}

const glyphs = {
    send: (
        <>
            <path d="M21 3 10.5 13.5" />
            <path d="M21 3 14 21l-3.5-7.5L3 10Z" />
        </>
    ),
    chevron: <path d="m14 6-6 6 6 6" />,
    menu: (
        <>
            <path d="M4 7h16" />
            <path d="M4 12h16" />
            <path d="M4 17h16" />
        </>
    ),
    globe: (
        <>
            <circle cx="12" cy="12" r="8" />
            <path d="M4 12h16" />
            <path d="M12 4c2.2 2.4 3.3 5.1 3.3 8s-1.1 5.6-3.3 8c-2.2-2.4-3.3-5.1-3.3-8s1.1-5.6 3.3-8Z" />
        </>
    ),
    bulb: (
        <>
            <path d="M9 18h6" />
            <path d="M10 21h4" />
            <path d="M8.5 14.5a3.5 3.5 0 1 1 7 0c0 1.4-.8 2.2-1.4 3h-4.2c-.6-.8-1.4-1.6-1.4-3Z" />
        </>
    ),
    document: (
        <>
            <path d="M7 3h7l5 5v13H7Z" />
            <path d="M14 3v5h5" />
            <path d="M9 13h6M9 16h4" />
        </>
    ),
    pen: (
        <>
            <path d="M4 20h4L18 10l-4-4L4 16Z" />
            <path d="m12 6 4 4" />
        </>
    ),
    stop: <rect x="7" y="7" width="10" height="10" />,
    clear: (
        <>
            <path d="M4 7h16" />
            <path d="M9 7V5h6v2" />
            <path d="M7 7l1 13h8l1-13" />
        </>
    ),
    alert: (
        <>
            <path d="M12 4 2.5 20h19z" />
            <path d="M12 10v4" />
            <path d="M12 17h.01" />
        </>
    ),
    clock: (
        <>
            <circle cx="12" cy="12" r="8" />
            <path d="M12 8v4l3 2" />
        </>
    ),
    tokens: (
        <>
            <path d="m12 3 9 5-9 5-9-5z" />
            <path d="m3 12 9 5 9-5" />
            <path d="m3 16 9 5 9-5" />
        </>
    ),
    model: (
        <>
            <rect x="7" y="7" width="10" height="10" />
            <rect x="10" y="10" width="4" height="4" />
            <path d="M10 7V4M14 7V4M10 20v-3M14 20v-3M7 10H4M7 14H4M20 10h-3M20 14h-3" />
        </>
    ),
    text: (
        <>
            <path d="M5 7h14" />
            <path d="M5 12h14" />
            <path d="M5 17h9" />
        </>
    ),
    image: (
        <>
            <rect x="4" y="5" width="16" height="14" rx="2" />
            <circle cx="9" cy="10" r="1.25" />
            <path d="m4 16 4.5-4.5 3 3L15 11l5 5" />
        </>
    ),
    close: (
        <>
            <path d="M7 7l10 10" />
            <path d="M17 7 7 17" />
        </>
    ),
    message: (
        <>
            <path d="M5 6h14v10H8l-3 3z" />
            <path d="M8 10h8M8 13h5" />
        </>
    ),
    user: (
        <>
            <circle cx="12" cy="8" r="3.25" />
            <path d="M5.5 19.25c.8-3.2 3.3-5 6.5-5s5.7 1.8 6.5 5" />
        </>
    ),
    working: (
        <>
            <circle cx="12" cy="12" r="8" opacity="0.28" />
            <path d="M12 4a8 8 0 0 1 8 8" />
        </>
    ),
};
