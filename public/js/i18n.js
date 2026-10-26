// The page's language (docs/spec/frontend.md, «Локализация»): the server picks it and writes it into <html lang>. The
// switch reloads the page with ?lang=, so it never changes while the page lives and modules pick their texts once.

export const LANGS = ['ru', 'en'];

/** `ru` or `en`; Russian outside a page (the tests) or for a language the page does not speak. */
export const lang = (() => {
    const of = globalThis.document?.documentElement?.lang;
    return LANGS.includes(of) ? of : 'ru';
})();

/** The variant for the page's language, the Russian one when there is none. */
export const pick = (variants) => variants[lang] ?? variants.ru;
