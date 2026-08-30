/**
 * A small, deterministic color identity per subject, so the catalogue reads as a
 * system rather than a wall of identical cards. `tint` is the accent (works on
 * both themes); soft backgrounds are derived from it with color-mix so they adapt
 * to whatever surface they sit on (light card / dark stage).
 */
export type SubjectTheme = { tint: string; name: string };

const PALETTES: SubjectTheme[] = [
    { name: 'teal', tint: '#0d8b7f' },
    { name: 'violet', tint: '#7c5cff' },
    { name: 'amber', tint: '#d98a2b' },
    { name: 'rose', tint: '#d95c7a' },
    { name: 'sky', tint: '#2b8fd6' },
    { name: 'green', tint: '#5a9e3f' },
    { name: 'indigo', tint: '#5b6cf0' },
    { name: 'clay', tint: '#c96f4a' },
];

export function subjectTheme(subject?: string | null): SubjectTheme {
    if (!subject) return PALETTES[0]!;
    let h = 0;
    for (const ch of subject) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return PALETTES[h % PALETTES.length]!;
}

/** A translucent wash of the tint over the current surface (theme-adaptive). */
export const soft = (tint: string, pct = 12) => `color-mix(in srgb, ${tint} ${pct}%, transparent)`;

const INK = '#17212b';

function channel(v: number): number {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
    const h = hex.replace('#', '');
    return (
        0.2126 * channel(parseInt(h.slice(0, 2), 16)) +
        0.7152 * channel(parseInt(h.slice(2, 4), 16)) +
        0.0722 * channel(parseInt(h.slice(4, 6), 16))
    );
}

/** The text colour (white or dark ink) with the higher contrast on a solid `tint`
 *  fill — so a tinted button/pill/badge never washes out (e.g. white on amber). */
export function onTint(tint: string): string {
    const l = luminance(tint);
    const contrastWithWhite = 1.05 / (l + 0.05);
    const contrastWithInk = (l + 0.05) / (luminance(INK) + 0.05);
    return contrastWithWhite >= contrastWithInk ? '#ffffff' : INK;
}
