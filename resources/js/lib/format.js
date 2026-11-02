const num = (v, digits) =>
  v === null || v === undefined || v === ''
    ? '—'
    : Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits });

export const price = (v) => num(v, 6);
export const money = (v) =>
  v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + num(v, 2);

export const pnlColor = (v) => (v > 0 ? '#15803d' : v < 0 ? '#b91c1c' : 'inherit');

// "2026-11-02T11:17" -> "2 Nov 2026, 11:17"
export const when = (s) =>
  s ? new Date(s).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

export const duration = (min) =>
  min === null || min === undefined ? '—' : min < 90 ? `${min} min` : min < 2880 ? `${(min / 60).toFixed(1)} h` : `${(min / 1440).toFixed(1)} d`;
