const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Display-only formatting of a server-computed amount, e.g. "1234.5" → "₱1,234.50".
 * Never use the result (or the input) for arithmetic in the browser.
 */
export function formatPeso(amount: string | number): string {
    const value = typeof amount === 'string' ? Number(amount) : amount;

    return Number.isFinite(value) ? peso.format(value) : '—';
}
