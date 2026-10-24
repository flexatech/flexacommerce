/**
 * Thin wrapper around `wp.i18n` so JS strings are translatable via the same
 * pipeline as the PHP side. The `wp-i18n` script is loaded as a dependency
 * by Enqueue.php; in dev mode (or in tests) we fall back to identity.
 *
 * Always pass literal strings as the first argument to `__` etc. so the
 * `wp i18n make-pot` extractor can find them statically.
 */

const TEXT_DOMAIN = "flexacommerce";

interface WpI18n {
    __(text: string, domain?: string): string;
    _x(text: string, context: string, domain?: string): string;
    _n(single: string, plural: string, count: number, domain?: string): string;
    sprintf(format: string, ...args: unknown[]): string;
}

declare global {
    interface WpGlobal {
        i18n?: WpI18n;
    }
    interface Window {
        wp?: WpGlobal;
    }
}

function api(): WpI18n | undefined {
    return window.wp?.i18n;
}

export function __(text: string): string {
    return api()?.__(text, TEXT_DOMAIN) ?? text;
}

export function _x(text: string, context: string): string {
    return api()?._x(text, context, TEXT_DOMAIN) ?? text;
}

export function _n(single: string, plural: string, count: number): string {
    return (
        api()?._n(single, plural, count, TEXT_DOMAIN) ??
        (count === 1 ? single : plural)
    );
}

export function sprintf(format: string, ...args: unknown[]): string {
    return api()?.sprintf(format, ...args) ?? format;
}
