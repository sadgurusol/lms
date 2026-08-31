import { useState } from 'react';

/** Share a link: the native share sheet where available (mobile), else a small
 *  popover with copy-link + the common socials. Purely client-side. */
export function ShareButton({
    url,
    title,
    text,
    className,
    tint,
}: {
    url: string;
    title: string;
    text?: string;
    className?: string;
    tint?: string;
}) {
    const [openMenu, setOpenMenu] = useState(false);
    const [copied, setCopied] = useState(false);
    const absolute = url.startsWith('http') ? url : `${window.location.origin}${url}`;
    const shareText = text ?? title;

    async function share() {
        // Native share sheet (mobile / some desktops).
        if (navigator.share) {
            try {
                await navigator.share({ title, text: shareText, url: absolute });
                return;
            } catch {
                /* user cancelled — fall through to the menu */
            }
        }
        setOpenMenu((o) => !o);
    }

    async function copy() {
        try {
            await navigator.clipboard.writeText(absolute);
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        } catch {
            /* clipboard blocked — ignore */
        }
    }

    const enc = encodeURIComponent;
    const links = [
        { label: 'WhatsApp', href: `https://wa.me/?text=${enc(`${shareText} ${absolute}`)}` },
        { label: 'X', href: `https://twitter.com/intent/tweet?text=${enc(shareText)}&url=${enc(absolute)}` },
        { label: 'Facebook', href: `https://www.facebook.com/sharer/sharer.php?u=${enc(absolute)}` },
        { label: 'Email', href: `mailto:?subject=${enc(title)}&body=${enc(`${shareText}\n\n${absolute}`)}` },
    ];

    return (
        <div className="relative inline-block">
            <button
                onClick={share}
                className={className ?? 'inline-flex items-center gap-2 rounded-full border border-[var(--line)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--accent)]'}
                style={tint ? { color: tint } : undefined}
                aria-label="Share"
            >
                <ShareIcon /> Share
            </button>

            {openMenu && (
                <>
                    <div className="fixed inset-0 z-40" onClick={() => setOpenMenu(false)} />
                    <div className="absolute left-0 z-50 mt-2 w-52 rounded-xl border border-[var(--line)] bg-[var(--card)] p-1.5 shadow-xl">
                        <button
                            onClick={() => {
                                void copy();
                            }}
                            className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-[var(--paper)]"
                        >
                            <span>Copy link</span>
                            {copied && <span className="text-xs font-semibold" style={{ color: tint ?? 'var(--accent)' }}>Copied!</span>}
                        </button>
                        <div className="my-1 border-t border-[var(--line)]" />
                        {links.map((l) => (
                            <a
                                key={l.label}
                                href={l.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => setOpenMenu(false)}
                                className="block rounded-lg px-3 py-2 text-sm hover:bg-[var(--paper)]"
                            >
                                {l.label}
                            </a>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

function ShareIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <circle cx="18" cy="5" r="3" />
            <circle cx="6" cy="12" r="3" />
            <circle cx="18" cy="19" r="3" />
            <line x1="8.6" y1="13.5" x2="15.4" y2="17.5" />
            <line x1="15.4" y1="6.5" x2="8.6" y2="10.5" />
        </svg>
    );
}
