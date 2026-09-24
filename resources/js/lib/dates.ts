const TZ = 'Asia/Manila';

const dateTime = new Intl.DateTimeFormat('en-PH', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' });
const dateOnly = new Intl.DateTimeFormat('en-PH', { timeZone: TZ, dateStyle: 'medium' });
const weekdayDate = new Intl.DateTimeFormat('en-PH', { timeZone: TZ, weekday: 'long', month: 'long', day: 'numeric' });

/** All dates are shown in shop time (Asia/Manila), whatever the device's timezone. */
export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTime.format(new Date(iso)) : '—';
}

export function formatDate(iso: string | null | undefined): string {
    return iso ? dateOnly.format(new Date(iso)) : '—';
}

export function formatDeadline(iso: string | null | undefined): string {
    return iso ? weekdayDate.format(new Date(iso)) : '—';
}
