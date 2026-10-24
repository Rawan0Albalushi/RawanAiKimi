export function formatLatency(milliseconds, copy) {
    if (milliseconds >= 10000) {
        return `${Math.round(milliseconds / 1000)} ${copy.seconds}`;
    }

    if (milliseconds >= 1000) {
        return `${(milliseconds / 1000).toFixed(1)} ${copy.seconds}`;
    }

    return `${milliseconds} ${copy.milliseconds}`;
}

export function formatWhen(value, locale) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const language = locale === 'ar' ? 'ar' : 'en';
    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();

    if (sameDay) {
        return date.toLocaleTimeString(language, { hour: '2-digit', minute: '2-digit' });
    }

    return date.toLocaleDateString(language, { month: 'short', day: 'numeric' });
}

export function formatClock(value, locale) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleTimeString(locale === 'ar' ? 'ar' : 'en', {
        hour: '2-digit',
        minute: '2-digit',
    });
}
