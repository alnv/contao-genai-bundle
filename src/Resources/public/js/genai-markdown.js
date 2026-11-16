/**
 * GenAI streaming Markdown helpers (no build step, UMD-style global).
 *
 * window.GenAIMarkdown = {
 *   render(markdown, {streaming}) -> sanitized HTML
 *   repair(markdown)              -> markdown that is safe to render mid-stream
 *   sanitize(html)                -> sanitized HTML (same rules as render)
 *   morph(targetEl, html)         -> patches targetEl's children to match html (no full rebuild)
 * }
 *
 * Requires marked (UMD, v15) and DOMPurify (v3). Without them a minimal escaped fallback is used.
 *
 * Streaming strategy: the final answer is always rendered with plain marked.parse(), so the
 * end result is identical to a complete render. While streaming, repair() only touches the
 * unfinished tail: it closes open code fences, inline code and emphasis, hides half-typed
 * block markers (#, -, 1., ---, a table header without its delimiter row) and renders
 * half-typed links as plain text until they are complete.
 */
(function (global) {
    'use strict';

    const SANITIZE_CONFIG = {
        ALLOWED_TAGS: [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'p', 'br', 'hr',
            'ul', 'ol', 'li',
            'strong', 'em', 'b', 'i', 'u', 's', 'del',
            'a', 'code', 'pre',
            'blockquote', 'q',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'img', 'figure', 'figcaption',
            'span', 'div', 'sup', 'sub', 'input'
        ],
        ALLOWED_ATTR: [
            'href', 'target', 'rel', 'title', 'alt', 'src',
            'class', 'colspan', 'rowspan', 'align', 'start',
            'type', 'checked', 'disabled'
        ],
        ALLOW_DATA_ATTR: false,
        FORBID_TAGS: ['script', 'style', 'iframe', 'form', 'button', 'object', 'embed', 'textarea', 'select'],
        FORBID_ATTR: ['style', 'id', 'name']
    };

    let markedInstance = null;
    let purifier = null;

    const getMarked = () => {
        if (markedInstance) return markedInstance;
        if (!global.marked || typeof global.marked.Marked !== 'function') return null;

        // Own instance: does not change the global marked defaults of the page
        markedInstance = new global.marked.Marked({gfm: true, breaks: true, pedantic: false});
        return markedInstance;
    };

    const getPurifier = () => {
        if (purifier) return purifier;
        if (typeof global.DOMPurify !== 'function') return null;

        // Own instance: hooks do not leak into other DOMPurify users on the page
        purifier = global.DOMPurify(global);

        purifier.addHook('afterSanitizeAttributes', (node) => {
            if (node.tagName === 'A' && node.hasAttribute('href')) {
                let external = true;
                try {
                    external = new URL(node.getAttribute('href'), global.location.href).origin !== global.location.origin;
                } catch (e) {
                    external = true;
                }
                if (external) {
                    node.setAttribute('target', '_blank');
                    node.setAttribute('rel', 'noopener noreferrer');
                } else {
                    node.removeAttribute('target');
                }
            }
            // GFM task list checkboxes only, never interactive
            if (node.tagName === 'INPUT') {
                if (node.getAttribute('type') !== 'checkbox') {
                    node.remove();
                } else {
                    node.setAttribute('disabled', '');
                }
            }
        });

        return purifier;
    };

    const escapeHtml = (text) => String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const sanitize = (html) => {
        const p = getPurifier();
        // Without DOMPurify never inject HTML: show it as text
        return p ? p.sanitize(html, SANITIZE_CONFIG) : escapeHtml(html);
    };

    /* ------------------------------------------------------------------ */
    /* repair(): make unfinished Markdown renderable                       */
    /* ------------------------------------------------------------------ */

    const FENCE_RE = /^ {0,3}(`{3,}|~{3,})(.*)$/;
    const TABLE_DELIMITER_RE = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/;
    // A last line that only consists of a block marker that is still being typed
    const PENDING_BLOCK_RE = /^\s{0,3}(#{1,6}|[-*+]|\d{1,9}[.)]?|>|`{1,2}|~{1,2}|[-=_*]{2,}|\|)\s*$/;

    /**
     * Returns the state of fenced code blocks: null when all fences are closed,
     * otherwise the fence string that closes the open block.
     */
    const openFence = (lines) => {
        let open = null;
        for (const line of lines) {
            const m = line.match(FENCE_RE);
            if (!m) continue;
            if (open === null) {
                // Backtick fences must not contain backticks in the info string
                if (m[1][0] === '`' && m[2].includes('`')) continue;
                open = m[1];
            } else if (m[1][0] === open[0] && m[1].length >= open.length && m[2].trim() === '') {
                open = null;
            }
        }
        return open;
    };

    /** Removes inline code spans so their content does not count as emphasis */
    const stripInlineCode = (text) => text.replace(/(`+)[\s\S]*?\1/g, '');

    const countToken = (text, token) => text.split(token).length - 1;

    const closeInline = (text) => {
        const lastBlank = text.search(/\n[ \t]*\n(?![\s\S]*\n[ \t]*\n)/);
        const prefix = lastBlank === -1 ? '' : text.slice(0, lastBlank + 1);
        let tail = lastBlank === -1 ? text : text.slice(lastBlank + 1);

        // Half-typed link / image: [text](http://exa  ->  text
        tail = tail.replace(/!?\[([^\]\n]*)\]\([^)\n]*$/, '$1');
        // Link text closed, URL not started yet: [text]  ->  text
        tail = tail.replace(/!?\[([^\]\n]*)\]$/, '$1');
        // Unclosed link text at the very end: [text  ->  text
        tail = tail.replace(/!?\[([^\]\n]*)$/, '$1');
        // Emphasis opener typed after a space but no text yet: "mit *", "mit **", "~"
        tail = tail.replace(/(^|\s)[*_~]+$/, '$1');

        // Unclosed inline code: odd number of backtick runs
        const runs = tail.match(/`+/g) || [];
        if (runs.length % 2 === 1) {
            const run = runs[runs.length - 1];
            if (tail.replace(/\s+$/, '').endsWith(run)) {
                tail = tail.replace(/\s+$/, '').slice(0, -run.length);
            } else {
                tail += run;
            }
            return prefix + tail;   // inside code no emphasis repair
        }

        if (!openEmphasis(tail).length) return prefix + tail;

        // A closing delimiter must not follow whitespace
        tail = tail.replace(/\s+$/, '');

        // Dangling opener without content yet ("text **") or a half-typed closer
        // ("~~text~" while "~~" is open) -> drop it instead of closing
        let dropped = true;
        while (dropped) {
            dropped = false;
            for (const token of openEmphasis(tail)) {
                if (tail.endsWith(token)) {
                    tail = tail.slice(0, -token.length).replace(/\s+$/, '');
                    dropped = true;
                    break;
                }
                if (token.length > 1 && tail.endsWith(token[0]) && !tail.endsWith(token)) {
                    tail = tail.slice(0, -1);
                    dropped = true;
                    break;
                }
            }
        }

        return prefix + tail + openEmphasis(tail).reverse().join('');
    };

    /**
     * Emphasis delimiters that are opened but not closed in the given text.
     * Order: **, __, ~~, * (closed in reverse order).
     */
    const openEmphasis = (text) => {
        const plain = stripInlineCode(text);
        const open = [];

        for (const token of ['**', '__', '~~']) {
            if (countToken(plain, token) % 2 === 1) open.push(token);
        }

        // Single * emphasis: ignore list bullets ("* item"), "**" pairs and free-standing " * "
        const singles = plain
            .replace(/^\s*\*\s+/gm, '')
            .replace(/\*\*/g, '')
            .replace(/(^|\s)\*(?=\s|$)/g, '$1');
        if (countToken(singles, '*') % 2 === 1) open.push('*');

        return open;
    };

    const repair = (markdown) => {
        if (!markdown) return '';

        let text = String(markdown).replace(/\r\n?/g, '\n');
        let lines = text.split('\n');

        // 1. Open fenced code block: close it, no further repair inside code
        const fence = openFence(lines);
        if (fence !== null) {
            // Closing fence still being typed ("`", "``") -> hide it
            const last = lines[lines.length - 1];
            if (!text.endsWith('\n') && new RegExp('^ {0,3}\\' + fence[0] + '{1,' + fence.length + '}$').test(last)) {
                lines.pop();
                text = lines.join('\n');
            }
            return text + (text.endsWith('\n') ? '' : '\n') + fence;
        }

        // 2. Unfinished last line that is only a block marker (#, -, 1., >, ``, ---, |)
        // (the line before it is then complete)
        let lastLineOpen = !text.endsWith('\n');
        if (lastLineOpen && PENDING_BLOCK_RE.test(lines[lines.length - 1])) {
            lines.pop();
            lastLineOpen = false;
        }

        // 3. Table whose delimiter row is not complete yet: hold the header back
        let end = lines.length;
        while (end > 0 && lines[end - 1].trim() === '') end--;
        let start = end;
        while (start > 0 && /^\s*\|/.test(lines[start - 1])) start--;
        const tableLines = lines.slice(start, end);
        if (tableLines.length === 1 || (tableLines.length === 2 && (!TABLE_DELIMITER_RE.test(tableLines[1]) || (end === lines.length && lastLineOpen)))) {
            lines = lines.slice(0, start);
        }

        text = lines.join('\n');

        // 4. Inline constructs of the last paragraph
        return closeInline(text);
    };

    /* ------------------------------------------------------------------ */
    /* render()                                                            */
    /* ------------------------------------------------------------------ */

    const fallbackParse = (text) => '<p>' + escapeHtml(text).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';

    const render = (markdown, options = {}) => {
        if (!markdown) return '';
        const source = options.streaming ? repair(markdown) : String(markdown);
        const m = getMarked();

        if (!m) return fallbackParse(source);

        try {
            return sanitize(m.parse(source));
        } catch (e) {
            console.error('[GenAI] Markdown parsing error:', e);
            return fallbackParse(source);
        }
    };

    /* ------------------------------------------------------------------ */
    /* morph(): patch DOM instead of replacing innerHTML                    */
    /* ------------------------------------------------------------------ */

    const syncAttributes = (target, source) => {
        for (const attr of Array.from(target.attributes)) {
            if (!source.hasAttribute(attr.name)) target.removeAttribute(attr.name);
        }
        for (const attr of Array.from(source.attributes)) {
            if (target.getAttribute(attr.name) !== attr.value) target.setAttribute(attr.name, attr.value);
        }
    };

    const morphChildren = (target, source) => {
        const targetNodes = Array.from(target.childNodes);
        const sourceNodes = Array.from(source.childNodes);

        sourceNodes.forEach((src, i) => {
            const cur = targetNodes[i];

            if (!cur) {
                target.appendChild(src.cloneNode(true));
                return;
            }

            if (cur.nodeType !== src.nodeType || cur.nodeName !== src.nodeName) {
                target.replaceChild(src.cloneNode(true), cur);
                return;
            }

            if (cur.nodeType === Node.TEXT_NODE || cur.nodeType === Node.COMMENT_NODE) {
                if (cur.nodeValue !== src.nodeValue) cur.nodeValue = src.nodeValue;
                return;
            }

            if (cur.nodeType === Node.ELEMENT_NODE) {
                syncAttributes(cur, src);
                morphChildren(cur, src);
            }
        });

        for (let i = targetNodes.length - 1; i >= sourceNodes.length; i--) {
            target.removeChild(targetNodes[i]);
        }
    };

    const morph = (target, html) => {
        const tpl = document.createElement('template');
        tpl.innerHTML = html || '';
        morphChildren(target, tpl.content);
    };

    global.GenAIMarkdown = {render, repair, sanitize, morph, escapeHtml};
})(window);
