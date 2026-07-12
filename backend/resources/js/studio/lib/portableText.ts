/**
 * Minimal Portable Text helpers: plain text ↔ paragraph blocks.
 *
 * The stored shape is the real Portable Text document, so a richer editor later
 * reads and writes the same content without a migration.
 */

type Span = { _type: 'span'; text: string; marks?: string[] };
type PtBlock = { _type: 'block'; style?: string; markDefs?: unknown[]; children: Span[] };

export function bodyToText(body: unknown): string {
    if (!Array.isArray(body)) return '';
    return body
        .map((block) => {
            const children = (block as PtBlock)?.children;
            if (!Array.isArray(children)) return '';
            return children.map((span) => span?.text ?? '').join('');
        })
        .join('\n');
}

export function textToBody(text: string): PtBlock[] {
    return text
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => ({
            _type: 'block',
            style: 'normal',
            markDefs: [],
            children: [{ _type: 'span', text: line, marks: [] }],
        }));
}
