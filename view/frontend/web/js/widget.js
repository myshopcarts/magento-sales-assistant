/**
 * Storefront Sales Assistant widget.
 *
 * Thin, self-contained (no external deps): renders a launcher + chat panel, and
 * on the first message fetches a short-lived token from the same-origin
 * `tokenUrl` (the server-side exchange — the secret key stays server-side), opens
 * the WebSocket at the returned `ws_url` with `?k=<token>`, and renders the
 * streamed frames: {type:chunk} appends tokens, {type:tool} shows "searching…",
 * {type:done} finalises, {type:error} shows a message. Reconnects by re-minting.
 *
 * DOM is built with createElement + textContent (never innerHTML) — XSS-safe per
 * Adobe guidance.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        var tokenUrl = config.tokenUrl;
        var searchUrl = config.searchUrl;
        var knowledgeUrl = config.knowledgeUrl;
        var greeting = config.greeting || '';   // merchant-configurable welcome message
        var assistantName = config.assistantName || '';  // sent to the backend for self-identification
        var businessContext = config.businessContext || '';  // what the store offers (reframes the persona)
        var callToAction = config.callToAction || '';  // where to send a ready-to-proceed shopper
        var attributeDescriptor = config.attributeDescriptor || [];  // store's filterable attribute vocabulary (§1b)
        var headerColor = config.headerColor || '';  // merchant-chosen header-bar colour (validated hex, server-side)
        // Black or white text for a given #RGB/#RRGGBB background, by perceived luminance, so the
        // header stays readable whatever colour the merchant picks.
        function pickTextColor(hex) {
            var h = hex.replace('#', '');
            if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
            var r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
            var lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            return lum > 0.6 ? '#1a1a2e' : '#fff';
        }
        var PLACEHOLDERS = ['Searching our catalogue…', 'Checking our help centre…'];
        var ws = null, sessionId = '', connecting = null, pending = [], curBubble = null;
        var lastSources = [];   // {url,title} of the current knowledge turn, shown as links on done
        var humanMode = false;  // a live human agent has joined — no bot bubbles; agent replies arrive as agent_msg

        // ---- session + transcript persistence (client-only) --------------------------------
        // Survive navigation between the store's pages: keep the session id + the rendered
        // transcript in sessionStorage (per tab, cleared when the tab closes — the right lifetime
        // for a shopping visit). No backend history fetch — the backend keeps the session's own
        // messages for LLM context; this only restores what the SHOPPER sees after a page load.
        var SID_KEY = 'msc_sa_sid', THREAD_KEY = 'msc_sa_thread';
        var thread = [];   // [{role, text}] of visible bubbles, replayed on load
        function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
        function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* private mode / quota */ } }
        function saveThread() { ssSet(THREAD_KEY, JSON.stringify(thread.slice(-100))); }
        function remember(role, text) { if (text) { thread.push({ role: role, text: text }); saveThread(); } }
        sessionId = ssGet(SID_KEY) || '';

        // ---- analytics (best-effort, generic) ------------------------------
        // Emit standard interaction events the merchant's OWN analytics can consume: pushed to the
        // GA4 / Tag Manager dataLayer and to gtag() when present, and dispatched as a DOM
        // CustomEvent ('salesassistant:<name>') for any other listener. No external calls, nothing
        // leaves the store, and it is a silent no-op when no analytics is installed. An analytics
        // failure must never break the widget.
        function track(name) {
            try {
                window.dataLayer = window.dataLayer || [];
                // gtag-command form — an arguments object, exactly what gtag('event', name) pushes.
                // The gtag.js library delivers it to GA4 whether gtag() is a global (standalone
                // gtag.js) OR module-local (Magento's built-in Google Analytics (GA4) integration,
                // which never exposes window.gtag). Verified live on a Magento-native store: the
                // {event:...} object form below is IGNORED by gtag.js (only GTM reads it), so this
                // arguments form is the one that actually reaches GA4.
                (function () { window.dataLayer.push(arguments); })('event', name);
                // GTM custom-event form — for a Tag Manager container with a trigger on this event.
                window.dataLayer.push({ event: name });
                // DOM event — for any other analytics listener.
                document.dispatchEvent(new CustomEvent('salesassistant:' + name));
            } catch (e) { /* best-effort — never break the widget */ }
        }

        // ---- DOM ------------------------------------------------------------
        var panel = el('div', 'msc-sa-panel'); panel.style.display = 'none';
        var log = el('div', 'msc-sa-log');
        var form = el('form', 'msc-sa-form');
        var input = el('input', 'msc-sa-input'); input.type = 'text';
        input.placeholder = 'Ask about our products…'; input.setAttribute('aria-label', 'Ask the assistant');
        var sendBtn = el('button', 'msc-sa-send'); sendBtn.type = 'submit'; sendBtn.textContent = 'Send';
        form.appendChild(input); form.appendChild(sendBtn);

        // Header with a minimize control so the shopper can dismiss the panel (keeps history —
        // reopening from the launcher shows the conversation again).
        var header = el('div', 'msc-sa-header');
        var title = el('span', 'msc-sa-title'); title.textContent = assistantName || 'Assistant';
        var minBtn = el('button', 'msc-sa-min'); minBtn.type = 'button';
        minBtn.setAttribute('aria-label', 'Minimize'); minBtn.title = 'Minimize'; minBtn.textContent = '–';
        minBtn.addEventListener('click', function () { panel.style.display = 'none'; lockScroll(false); });
        header.appendChild(title); header.appendChild(minBtn);
        // Optional merchant header colour (Stores → Config → Sales Assistant). The value is a
        // server-validated hex literal, so it's safe to write to inline style. It themes the whole
        // primary set — the header bar, the Send button and the Chat launcher (all the same dark
        // colour by default) — with the text flipped black/white by luminance so each stays legible
        // on any colour. (The launcher gets the same treatment where it's created, below.)
        if (headerColor) {
            var textColor = pickTextColor(headerColor);
            header.style.background = headerColor;
            header.style.color = textColor;
            minBtn.style.color = textColor;
            sendBtn.style.background = headerColor;
            sendBtn.style.color = textColor;
        }

        panel.appendChild(header); panel.appendChild(log); panel.appendChild(form);

        // On mobile the panel is a full-screen sheet (see widget.css). The on-screen keyboard
        // otherwise sits ON TOP of a position:fixed panel, hiding the input — so pin the panel to
        // the VISUAL viewport (the area above the keyboard) whenever it shrinks. Desktop/tablet
        // (>480px) keep the CSS card, so we clear the inline overrides there.
        function fitViewport() {
            var vv = window.visualViewport;
            if (!vv || panel.style.display === 'none' || window.innerWidth > 480) {
                panel.style.top = ''; panel.style.height = ''; return;
            }
            panel.style.top = vv.offsetTop + 'px';
            panel.style.height = vv.height + 'px';
        }
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', fitViewport);
            window.visualViewport.addEventListener('scroll', fitViewport);
        }

        // Mobile background scroll-lock. The full-screen sheet is position:fixed, and a fixed
        // overlay does NOT stop the page BEHIND it from scrolling on mobile — so opening the chat
        // let the storefront pan left/right behind the sheet. Pin the body while the sheet is open
        // (save + restore the scroll position) so the background can't move. Desktop keeps the
        // floating card, which never covers the page, so no lock there.
        var savedScrollY = 0;
        function lockScroll(lock) {
            if (window.innerWidth > 480) { return; }
            var b = document.body;
            if (lock) {
                savedScrollY = window.scrollY || window.pageYOffset || 0;
                b.style.position = 'fixed';
                b.style.top = (-savedScrollY) + 'px';
                b.style.left = '0';
                b.style.right = '0';
                b.style.width = '100%';
                b.style.overflow = 'hidden';
            } else {
                b.style.position = ''; b.style.top = ''; b.style.left = '';
                b.style.right = ''; b.style.width = ''; b.style.overflow = '';
                window.scrollTo(0, savedScrollY);
            }
        }

        var launcher = el('button', 'msc-sa-launcher'); launcher.type = 'button';
        launcher.textContent = 'Chat'; launcher.setAttribute('aria-label', 'Open shopping assistant');
        if (headerColor) { launcher.style.background = headerColor; launcher.style.color = pickTextColor(headerColor); }
        launcher.addEventListener('click', function () {
            var opening = panel.style.display === 'none';
            panel.style.display = opening ? 'flex' : 'none';
            lockScroll(opening);
            if (opening) {
                track('chat_open');
                // Greet on first open so the panel isn't empty (once — a reopen keeps history).
                if (greeting && !log.children.length) { bubble('assistant', greeting); }
                fitViewport();
                // Only auto-focus on desktop. On mobile, focusing pops the on-screen keyboard the
                // instant the sheet opens — which triggers the viewport zoom/scroll jank behind it
                // (and buries the greeting). The shopper taps the field when they're ready to type.
                if (window.innerWidth > 480) { input.focus(); }
            }
        });

        element.appendChild(launcher); element.appendChild(panel);

        // Redraw a prior conversation (same tab) after a page navigation, from sessionStorage.
        (function rehydrate() {
            var raw = ssGet(THREAD_KEY);
            if (!raw) { return; }
            try {
                var msgs = JSON.parse(raw);
                if (!Array.isArray(msgs)) { return; }
                msgs.forEach(function (m) {
                    if (!m || !m.role || typeof m.text !== 'string') { return; }
                    var b = bubble(m.role, m.text);
                    if (m.role === 'assistant' || m.role === 'agent') { linkify(b); }
                });
                thread = msgs.slice(-100);
            } catch (e) { /* corrupt/blocked storage — start fresh */ }
        })();

        // ---- messages -------------------------------------------------------
        // Keep the newest content in view. rAF so the scroll runs AFTER the browser lays out the
        // just-changed content: appending a bubble, setting the "Searching…" placeholder, or adding a
        // stream chunk all GROW the log, and a synchronous scrollTop can read a stale scrollHeight and
        // leave the new text below the fold — the "only a sliver shows until the answer is ready" jank.
        function scrollToBottom() {
            requestAnimationFrame(function () { log.scrollTop = log.scrollHeight; });
        }

        function bubble(role, text) {
            var b = el('div', 'msc-sa-msg msc-sa-' + role);
            b.textContent = text || '';
            log.appendChild(b); scrollToBottom();
            return b;
        }

        // ---- one bot turn at a time ---------------------------------------
        // A message typed while the bot is still answering is shown immediately but SENT only once
        // the current turn ends (done/error). Sending it straight away ran two turns concurrently
        // on the backend: replies came back out of order, chunks landed in the wrong bubble, and
        // the handoff snapshot missed the in-flight answer. In human mode there is no bot turn to
        // wait for, so messages go straight through.
        var busy = false, outbox = [], busyTimer = null, TURN_TIMEOUT_MS = 45000;
        function dispatch(text) {
            lastSources = [];
            // In human mode the reply comes from a person (as an agent_msg), not the bot — don't
            // open an empty assistant bubble that would spin forever waiting for a stream.
            curBubble = humanMode ? null : bubble('assistant', '');
            busy = !humanMode;
            if (busy) { busyTimer = setTimeout(abandonTurn, TURN_TIMEOUT_MS); }
            ensureSocket().then(function () {
                ws.send(JSON.stringify({ action: 'message', text: text, assistant_name: assistantName, business_context: businessContext, call_to_action: callToAction, attribute_descriptor: attributeDescriptor }));
            }).catch(function () {
                if (curBubble) { curBubble.textContent = 'Sorry — the assistant is unavailable right now.'; }
                curBubble = null;
                settle();
            });
        }
        // The current turn is over — release the next queued message, if any.
        function settle() {
            busy = false;
            if (busyTimer) { clearTimeout(busyTimer); busyTimer = null; }
            // Bot mode: dispatch() re-arms `busy`, so one message goes per turn. Human mode: no
            // turn to wait for, so the whole queue drains to the agent.
            while (!busy && outbox.length) { dispatch(outbox.shift()); }
        }
        // No done/error arrived (lost frame, dropped socket) — don't leave the queue stuck.
        function abandonTurn() {
            busyTimer = null;
            if (!busy) { return; }
            if (curBubble && (!curBubble.textContent || isPlaceholder(curBubble.textContent))) {
                curBubble.textContent = 'Sorry — that took too long. Please try again.';
            }
            curBubble = null;
            settle();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = input.value.trim();
            if (!text) { return; }
            input.value = '';
            track('chat_message_sent');
            bubble('user', text);
            remember('user', text);
            if (busy) { outbox.push(text); return; }
            dispatch(text);
        });

        // ---- WebSocket ------------------------------------------------------
        function ensureSocket() {
            if (ws && ws.readyState === 1) { return Promise.resolve(); }
            if (connecting) { return connecting; }
            connecting = fetch(tokenUrl + (sessionId ? '?session_id=' + encodeURIComponent(sessionId) : ''), {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.token || !d.ws_url) { throw new Error('no token'); }
                sessionId = d.session_id || sessionId;
                ssSet(SID_KEY, sessionId);   // persist so navigation resumes the same conversation
                return openSocket(d.ws_url + '?k=' + encodeURIComponent(d.token));
            }).then(function () { connecting = null; }, function (err) { connecting = null; throw err; });
            return connecting;
        }

        function openSocket(url) {
            return new Promise(function (resolve, reject) {
                var sock = new WebSocket(url);
                sock.addEventListener('open', function () { ws = sock; resolve(); });
                sock.addEventListener('error', function () { reject(new Error('ws error')); });
                sock.addEventListener('close', function () {
                    if (ws !== sock) { return; }
                    ws = null;
                    // The socket died mid-turn: the reply will never arrive on it. Close the turn so
                    // queued messages go out on a fresh connection instead of waiting for the timeout.
                    if (busy) {
                        if (curBubble && (!curBubble.textContent || isPlaceholder(curBubble.textContent))) {
                            curBubble.textContent = 'Sorry — the connection dropped. Please try again.';
                        }
                        curBubble = null;
                        settle();
                    }
                });
                sock.addEventListener('message', onFrame);
            });
        }

        function onFrame(ev) {
            var f; try { f = JSON.parse(ev.data); } catch (e) { return; }
            if (f.type === 'chunk') {
                // A stream chunk always belongs in an assistant bubble; create one if a prior
                // human-mode reply left us without a current bubble (e.g. the bot has resumed).
                if (!curBubble) { curBubble = bubble('assistant', ''); }
                if (isPlaceholder(curBubble.textContent)) { curBubble.textContent = ''; }
                curBubble.textContent += (f.text || ''); scrollToBottom();
            } else if (f.type === 'agent_joined') {
                humanMode = true;
                if (curBubble && !curBubble.textContent) { curBubble.parentNode && curBubble.parentNode.removeChild(curBubble); curBubble = null; }
                // A person has the chat now: anything queued goes straight to them (dispatch in
                // human mode doesn't wait). An in-flight bot turn still ends with its own done.
                if (!curBubble && busy) { settle(); }
                var note = el('div', 'msc-sa-note');
                note.textContent = (f.agent_name || 'Someone') + ' from the team joined the chat.';
                log.appendChild(note); scrollToBottom();
            } else if (f.type === 'agent_msg') {
                var ab = bubble('agent', f.text || '');
                if (f.agent_name) { ab.setAttribute('data-agent', f.agent_name); }
                remember('agent', f.text || '');
            } else if (f.type === 'search_both') {
                if (curBubble && !curBubble.textContent) { curBubble.textContent = PLACEHOLDERS[0]; scrollToBottom(); }
                runBoth(f.tool_call_id, f.query, f.filters);
            } else if (f.type === 'search') {
                if (curBubble && !curBubble.textContent) { curBubble.textContent = PLACEHOLDERS[0]; scrollToBottom(); }
                runSearch(f.tool_call_id, f.query, f.filters);
            } else if (f.type === 'search_knowledge') {
                if (curBubble && !curBubble.textContent) { curBubble.textContent = PLACEHOLDERS[1]; scrollToBottom(); }
                runKnowledge(f.tool_call_id, f.query);
            } else if (f.type === 'done') {
                if (curBubble) {
                    if (isPlaceholder(curBubble.textContent)) { curBubble.textContent = ''; }
                    linkify(curBubble);   // turn any http(s) URLs in the answer into clickable links
                    remember('assistant', curBubble.textContent);
                }
                // Prefer the backend-attributed sources (only the passages the answer actually used;
                // suppressed on a product-dominant answer) — falls back to the locally-retrieved set
                // if an older backend didn't send `sources`.
                renderSources(Array.isArray(f.sources) ? f.sources : lastSources);
                scrollToBottom();   // final answer + sources fully in view
                curBubble = null;
                settle();
            } else if (f.type === 'error') {
                if (curBubble) { curBubble.textContent = f.message || 'Something went wrong.'; }
                curBubble = null;
                settle();
            }
        }

        // Run local catalogue search (server-side controller → merchant OpenSearch)
        // and return the products to the backend to answer over.
        function runSearch(toolCallId, query, filters) {
            fetch(searchUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query || '', filters: filters || {} })
            }).then(function (r) { return r.json(); })
              .then(function (d) { reply(toolCallId, (d && d.products) || [], (d && d.facts) || null); })
              .catch(function () { reply(toolCallId, [], null); });
        }

        // `facts` (may be null) carries whole-set count/price-range for aggregate/enumeration turns.
        function reply(toolCallId, products, facts) {
            if (ws && ws.readyState === 1) {
                ws.send(JSON.stringify({ action: 'search_result', tool_call_id: toolCallId, products: products, facts: facts, assistant_name: assistantName, business_context: businessContext, call_to_action: callToAction }));
            }
        }

        // Run local KNOWLEDGE search (server-side controller → merchant OpenSearch,
        // eligibility-scoped server-side) and return the passages to the backend.
        function runKnowledge(toolCallId, query) {
            if (!knowledgeUrl) { replyKnowledge(toolCallId, []); return; }
            fetch(knowledgeUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query || '' })
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  var passages = (d && d.passages) || [];
                  lastSources = passages.map(function (p) { return { url: p.url || '', title: p.title || '' }; });
                  replyKnowledge(toolCallId, passages);
              })
              .catch(function () { replyKnowledge(toolCallId, []); });
        }

        // After a knowledge answer, show the unique source pages as clickable links so the
        // shopper can read more. Deterministic (from the retrieved passages), not LLM text.
        function renderSources(sources) {
            var seen = {}, items = [];
            (sources || []).forEach(function (s) {
                if (!s.url || !/^https?:\/\//i.test(s.url) || seen[s.url]) { return; }
                seen[s.url] = 1; items.push(s);
            });
            if (!items.length) { return; }
            items = items.slice(0, 3);
            var box = el('div', 'msc-sa-sources');
            box.appendChild(document.createTextNode('Sources: '));
            items.forEach(function (s, i) {
                if (i) { box.appendChild(document.createTextNode(' · ')); }
                var a = el('a', 'msc-sa-source-link');
                a.href = s.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.textContent = s.title || s.url;
                box.appendChild(a);
            });
            log.appendChild(box); scrollToBottom();
        }

        function replyKnowledge(toolCallId, passages) {
            if (ws && ws.readyState === 1) {
                ws.send(JSON.stringify({ action: 'knowledge_result', tool_call_id: toolCallId, passages: passages, assistant_name: assistantName, business_context: businessContext, call_to_action: callToAction }));
            }
        }

        // Retrieve-from-both: run the catalogue AND knowledge searches for one turn and return both
        // sets together, so a lookup never dead-ends on the planner picking a single wrong source.
        // Both are LOCAL server-side searches (merchant OpenSearch); run them in parallel and reply
        // once both settle. A failed/absent side degrades to [] rather than losing the whole turn.
        function runBoth(toolCallId, query, filters) {
            var pProducts = fetch(searchUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query || '', filters: filters || {} })
            }).then(function (r) { return r.json(); })
              .then(function (d) { return { products: (d && d.products) || [], facts: (d && d.facts) || null }; })
              .catch(function () { return { products: [], facts: null }; });
            var pPassages = knowledgeUrl ? fetch(knowledgeUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query || '' })
            }).then(function (r) { return r.json(); })
              .then(function (d) { return (d && d.passages) || []; })
              .catch(function () { return []; }) : Promise.resolve([]);
            Promise.all([pProducts, pPassages]).then(function (res) {
                var products = res[0].products, facts = res[0].facts, passages = res[1];
                lastSources = passages.map(function (p) { return { url: p.url || '', title: p.title || '' }; });
                if (ws && ws.readyState === 1) {
                    ws.send(JSON.stringify({ action: 'search_both_result', tool_call_id: toolCallId,
                        products: products, facts: facts, passages: passages, assistant_name: assistantName,
                        business_context: businessContext, call_to_action: callToAction }));
                }
            });
        }

        function isPlaceholder(t) { return PLACEHOLDERS.indexOf(t) !== -1; }

        // Rebuild a finished bubble's plain text as text + clickable <a> for any http(s) URLs.
        // XSS-safe: href comes only from a matched http(s) URL; all other text stays textContent.
        function linkify(node) {
            var text = node.textContent || '';
            // Collapse markdown links [label](url) -> the bare url. The model sometimes emits
            // [url](url) (and even a trailing (url)); we render plain text, so the markdown would
            // show literally and duplicate the link.
            text = text.replace(/\[[^\]]*\]\((https?:\/\/[^\s)]+)\)/g, '$1');
            // Bare-URL match STOPS at whitespace and bracket/paren/quote chars, so a URL never merges
            // with an adjacent "(url)" or "](url)" (the old [^\s]+ swallowed the whole run into one
            // mangled link).
            var re = /https?:\/\/[^\s<>()\[\]"']+/g;
            if (!re.test(text)) { node.textContent = text; return; }
            re.lastIndex = 0;
            node.textContent = '';
            var last = 0, m, lastUrl = null;
            while ((m = re.exec(text)) !== null) {
                var url = m[0], trail = '';
                var mt = url.match(/[.,;:!?]+$/);   // trailing sentence punctuation (brackets already excluded)
                if (mt) { trail = mt[0]; url = url.slice(0, url.length - trail.length); }
                var between = text.slice(last, m.index);
                // Drop an immediately-repeated identical URL separated only by brackets/space/punct
                // (the model prints the same link twice, e.g. "url)(url)").
                if (url === lastUrl && /^[\s()\[\].,;:]*$/.test(between)) {
                    last = m.index + m[0].length;
                    continue;
                }
                if (between) { node.appendChild(document.createTextNode(between)); }
                var a = el('a', 'msc-sa-link');
                a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.textContent = url;
                node.appendChild(a);
                if (trail) { node.appendChild(document.createTextNode(trail)); }
                last = m.index + m[0].length;
                lastUrl = url;
            }
            if (last < text.length) { node.appendChild(document.createTextNode(text.slice(last))); }
        }

        function el(tag, cls) { var n = document.createElement(tag); if (cls) { n.className = cls; } return n; }
    };
});
