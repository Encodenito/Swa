/** Shared shapes and display helpers for the staff CRM pages (display only; the server decides everything). */

export type TagColour = 'neutral' | 'blue' | 'green' | 'amber' | 'red' | 'violet';
export type ChipTone = 'neutral' | 'info' | 'accent' | 'warning' | 'danger';

export const TAG_COLOURS: { value: TagColour; label: string }[] = [
    { value: 'neutral', label: 'Grey' },
    { value: 'blue', label: 'Blue' },
    { value: 'green', label: 'Green' },
    { value: 'amber', label: 'Amber' },
    { value: 'red', label: 'Red' },
    { value: 'violet', label: 'Violet' },
];

export interface TagRef {
    id?: number;
    name: string;
    slug: string;
    colour: string;
}

/** CustomerController::followUp(). `due_on` is a plain Y-m-d date in shop time. */
export interface FollowUpRow {
    id: number;
    due_on: string;
    note: string;
    overdue: boolean;
    done: boolean;
    completed_at: string | null;
    author: string | null;
    assignee: string | null;
    reservation: string | null;
    customer?: { id: number; name: string | null } | null;
}

/** Chip has no green tone, so green falls back to neutral. */
export function tagTone(colour: string): ChipTone {
    return (
        (
            {
                blue: 'info',
                violet: 'accent',
                amber: 'warning',
                red: 'danger',
            } as Record<string, ChipTone>
        )[colour] ?? 'neutral'
    );
}

/** Today's date in shop time as Y-m-d (for `min` on date inputs). */
export function manilaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** A "Y-m-d H:i:s" value with no offset is shop time; turn it into ISO with +08:00. */
export function manilaIso(local: string | null | undefined): string | null {
    if (!local) return null;
    if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(local)) return local;

    return `${local.replace(' ', 'T')}+08:00`;
}

/** A Y-m-d date shown in shop time (noon avoids any day shift). */
export function dateOnlyIso(date: string): string {
    return `${date}T12:00:00+08:00`;
}

/** "ReservationConfirmed" → "Reservation confirmed". */
export function humanizeNotification(name: string): string {
    const words = name
        .replace(/Notification$/, '')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .trim();
    if (words === '') return name;

    return words.charAt(0).toUpperCase() + words.slice(1).toLowerCase();
}

export function channelLabel(channel: string): string {
    return ({ mail: 'Email', sms: 'SMS', database: 'In-app' } as Record<string, string>)[channel] ?? channel;
}
