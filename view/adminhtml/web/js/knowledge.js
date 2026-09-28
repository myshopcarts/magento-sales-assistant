/**
 * Admin Knowledge Documents (K2b brick 4 + K3).
 *  - Single file / URL: full preview → edit → Add (the quality gate).
 *  - Multiple files: preview the FIRST as a sample, then "Add all" indexes each without
 *    per-file review (reviewing every file doesn't scale). Files that extract to no
 *    usable text (0 sections / error) are auto-skipped and reported.
 *  - Delete removes a document from the index + list.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    var MAX_FILES = 20; // per batch

    return function (config, element) {
        var root = $(element);
        var fileEl = root.find('[data-role=file]');
        var status = root.find('[data-role=status]');
        var messagesEl = root.find('[data-role=messages]');
        var previewArea = root.find('[data-role=preview-area]');
        var titleEl = root.find('[data-role=title]');
        var markdownEl = root.find('[data-role=markdown]');
        var chunkInfo = root.find('[data-role=chunkinfo]');
        var warnEl = root.find('[data-role=warn]');
        var sampleNote = root.find('[data-role=samplenote]');
        var saveBtn = root.find('[data-role=save]');
        var addAllBtn = root.find('[data-role=addall]');
        var pending = {};   // current single doc (file or URL)
        var batch = [];     // files for a multi "Add all"

        // A prominent status line: a spinner + accent pill while busy, a red pill on error.
        // Class-driven (see the template's <style>) so loading/preview text is easy to notice.
        function setStatus(msg, isError) {
            status.text(msg || '').removeClass('msc-sa-status--busy msc-sa-status--error');
            if (msg) { status.addClass(isError ? 'msc-sa-status--error' : 'msc-sa-status--busy'); }
        }

        // A persistent, styled admin message box (success | warning | error | notice).
        // Built with textContent — never innerHTML — so content stays XSS-safe.
        function showMessage(text, type) {
            var box = $('<div/>').addClass('message message-' + (type || 'notice'));
            box.append($('<span/>').text(text || ''));
            messagesEl.empty().append(box);
        }

        // Show a result, then reload so the grid refreshes — the message survives the reload
        // (stashed in sessionStorage) so it doesn't vanish before it can be read.
        function reloadWith(text, type) {
            try { window.sessionStorage.setItem('mscSaMsg', JSON.stringify({ t: text, k: type })); } catch (e) {}
            window.location.reload();
        }

        // On load, surface a message carried across a reload.
        try {
            var carried = window.sessionStorage.getItem('mscSaMsg');
            if (carried) { window.sessionStorage.removeItem('mscSaMsg'); var m = JSON.parse(carried); showMessage(m.t, m.k); }
        } catch (e) {}

        // Extract a file for preview (upload → /extract). Returns a jqXHR of the result.
        function extractFile(file) {
            var fd = new FormData();
            fd.append('file', file);
            fd.append('form_key', window.FORM_KEY);
            return $.ajax({ url: config.previewUrl, method: 'POST', data: fd, processData: false, contentType: false });
        }

        function saveDoc(data) {
            data.form_key = window.FORM_KEY;
            return $.post(config.saveUrl, data);
        }

        // ---- single: full preview + edit ------------------------------------
        function showSingle(r, extra) {
            pending = extra;
            batch = [];
            titleEl.val(r.title || '');
            markdownEl.val(r.markdown || '').prop('readonly', false);
            chunkInfo.text($t('Approximately %1 section(s) will be indexed.').replace('%1', r.chunk_count));
            if (r.warning) { warnEl.text(r.warning).show(); } else { warnEl.hide(); }
            sampleNote.hide();
            saveBtn.show();
            addAllBtn.hide();
            previewArea.show();
            setStatus('');
        }

        function runFilePreview() {
            var files = (fileEl[0] && fileEl[0].files) ? Array.prototype.slice.call(fileEl[0].files) : [];
            if (!files.length) { setStatus($t('Choose a file first.'), true); return; }
            if (files.length > MAX_FILES) {
                setStatus($t('Please add up to %1 files at a time.').replace('%1', MAX_FILES), true);
                return;
            }
            if (files.length === 1) {
                setStatus($t('Uploading and extracting…'));
                extractFile(files[0])
                    .done(function (r) {
                        if (!r || !r.success) { showMessage((r && r.error) || $t('Preview failed.'), 'error'); return; }
                        showSingle(r, { s3_key: r.s3_key, source_id: r.source_id, filename: files[0].name });
                    })
                    .fail(function () { showMessage($t('Preview request failed.'), 'error'); });
                return;
            }
            // multiple: sample the first, offer "Add all"
            batch = files;
            pending = {};
            setStatus($t('Extracting a preview of the first file…'));
            extractFile(files[0])
                .done(function (r) {
                    titleEl.val((r && r.title) || files[0].name);
                    markdownEl.val((r && r.markdown) || '').prop('readonly', true);
                    chunkInfo.text('');
                    if (r && r.warning) { warnEl.text(r.warning).show(); } else { warnEl.hide(); }
                    sampleNote.text($t('Preview of the first file only — %1 files selected. "Add all" indexes each; files with no readable text are skipped.').replace('%1', files.length)).show();
                    saveBtn.hide();
                    addAllBtn.show();
                    previewArea.show();
                    setStatus('');
                })
                .fail(function () { showMessage($t('Preview request failed.'), 'error'); });
        }
        // Auto-preview as soon as files are chosen — there is no separate Preview button.
        root.on('change', '[data-role=file]', function () {
            if (fileEl[0] && fileEl[0].files && fileEl[0].files.length) { runFilePreview(); }
        });

        function submitSingle(allowDuplicate) {
            var data = {
                source_id: pending.source_id, s3_key: pending.s3_key || '', url: pending.url || '',
                filename: pending.filename || '', title: titleEl.val(), markdown: markdownEl.val()
            };
            if (allowDuplicate) { data.allow_duplicate = 1; }
            setStatus($t('Adding to knowledge base…'));
            saveDoc(data).done(function (r) {
                if (r && r.duplicate) {
                    if (window.confirm((r.error || '') + '\n' + $t('Add it anyway?'))) { submitSingle(true); }
                    else { setStatus($t('Not added (duplicate).')); }
                    return;
                }
                if (!r || !r.success) { showMessage((r && r.error) || $t('Save failed.'), 'error'); return; }
                reloadWith($t('Added “%1” to the knowledge base.').replace('%1', titleEl.val() || pending.filename || ''), 'success');
            }).fail(function () { showMessage($t('Save request failed.'), 'error'); });
        }

        root.on('click', '[data-role=save]', function () {
            if (!pending.source_id) { return; }
            if ($.trim(markdownEl.val()) === '') { setStatus($t('Nothing to add.'), true); return; }
            submitSingle(false);
        });

        // ---- multi: add all, skip the unreadable ----------------------------
        root.on('click', '[data-role=addall]', function () {
            if (!batch.length) { return; }
            addAllBtn.prop('disabled', true);
            var i = 0, added = 0, skipped = [], warned = [];
            function skip(name, reason) { skipped.push(name + ': ' + String(reason).replace(/\.\s*$/, '')); }
            function done() {
                addAllBtn.prop('disabled', false);
                var msg = $t('Added %1 document(s).').replace('%1', added);
                if (warned.length) {
                    msg += ' ' + $t('%1 may be scans (little text found): %2')
                        .replace('%1', warned.length).replace('%2', warned.join(', '));
                }
                if (skipped.length) {
                    msg += ' ' + $t('Skipped %1 — %2').replace('%1', skipped.length).replace('%2', skipped.join('; '));
                }
                var type = added > 0 ? (skipped.length || warned.length ? 'warning' : 'success')
                                     : (skipped.length ? 'error' : 'notice');
                reloadWith(msg, type);
            }
            function step() {
                if (i >= batch.length) { done(); return; }
                var file = batch[i]; i++;
                setStatus($t('Adding %1 of %2…').replace('%1', i).replace('%2', batch.length) + ' ' + file.name);
                extractFile(file)
                    .done(function (r) {
                        if (!r || !r.success) { skip(file.name, (r && r.error) || $t('could not be read')); step(); return; }
                        if (!r.chunk_count || $.trim(r.markdown || '') === '') { skip(file.name, $t('no readable text found')); step(); return; }
                        saveDoc({
                            source_id: r.source_id, s3_key: r.s3_key || '', url: '',
                            filename: file.name, title: r.title || file.name, markdown: r.markdown
                        }).done(function (s) {
                            if (s && s.success) { added++; if (r.warning) { warned.push(file.name); } }
                            else { skip(file.name, (s && s.error) || $t('could not be indexed')); }
                            step();
                        }).fail(function () { skip(file.name, $t('could not be indexed')); step(); });
                    })
                    .fail(function () { skip(file.name, $t('could not be read')); step(); });
            }
            step();
        });

        root.on('click', '[data-role=cancel]', function () {
            previewArea.hide();
            pending = {};
            batch = [];
            setStatus('');
        });

        // ---- documents: select (row checkboxes + select-all) → bulk delete ----
        var docTable = root.find('[data-role=doclist]');
        function checkedIds() {
            return docTable.find('tbody [data-role=doc-check]:checked').closest('tr')
                .map(function () { return $(this).data('source-id'); }).get()
                .filter(function (s) { return s !== undefined && s !== ''; });
        }
        function refreshSel() {
            var boxes = docTable.find('tbody [data-role=doc-check]');
            var n = boxes.filter(':checked').length;
            root.find('[data-role=doc-selcount]').text(n ? n + ' ' + $t('selected') : '');
            root.find('[data-role=delete-selected]').prop('disabled', n === 0);
            root.find('[data-role=set-priority]').prop('disabled', n === 0);
            root.find('[data-role=doc-selectall]').prop('checked', boxes.length > 0 && n === boxes.length);
        }
        root.on('change', '[data-role=doc-selectall]', function () {
            docTable.find('tbody [data-role=doc-check]').prop('checked', this.checked);
            refreshSel();
        });
        root.on('change', '[data-role=doc-check]', refreshSel);

        root.on('click', '[data-role=delete-selected]', function () {
            var ids = checkedIds();
            if (!ids.length) { return; }
            if (!window.confirm($t('Delete the %1 selected document(s) from the knowledge base?').replace('%1', ids.length))) { return; }
            $.post(config.deleteUrl, { form_key: window.FORM_KEY, source_ids: JSON.stringify(ids) })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Delete failed.'), 'error'); return; }
                    reloadWith($t('Removed %1 document(s) from the knowledge base.').replace('%1', r.deleted || ids.length), 'success');
                }).fail(function () { showMessage($t('Delete request failed.'), 'error'); });
        });

        // ---- Set retrieval priority on the selected documents/crawled pages ---
        root.on('click', '[data-role=set-priority]', function () {
            var ids = checkedIds();
            if (!ids.length) { return; }
            var level = root.find('[data-role=priority-level]').val();
            $.post(config.priorityUrl, { form_key: window.FORM_KEY, source_ids: JSON.stringify(ids), priority: level })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Could not set priority.'), 'error'); return; }
                    reloadWith($t('Priority updated for %1 document(s).').replace('%1', r.updated || ids.length), 'success');
                }).fail(function () { showMessage($t('Priority request failed.'), 'error'); });
        });

        // ---- Clear knowledge base (nuke) — drops ONLY our knowledge index -----
        root.on('click', '[data-role=clear-all]', function () {
            if (!window.confirm($t('Clear the ENTIRE knowledge base? This removes every document and empties this store’s assistant knowledge index. It cannot be undone.'))) { return; }
            if (!window.confirm($t('Are you sure? Everything in the assistant’s knowledge will be deleted.'))) { return; }
            $.post(config.clearUrl, { form_key: window.FORM_KEY })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Clear failed.'), 'error'); return; }
                    reloadWith($t('Knowledge base cleared.'), 'success');
                }).fail(function () { showMessage($t('Clear request failed.'), 'error'); });
        });

        // ---- Rebuild the list from the index (backfill the registry from OpenSearch) ----
        root.on('click', '[data-role=rebuild-list]', function () {
            var btn = $(this).prop('disabled', true);
            $.post(config.rebuildUrl, { form_key: window.FORM_KEY })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Rebuild failed.'), 'error'); btn.prop('disabled', false); return; }
                    reloadWith($t('List rebuilt from the index.') + ' (' + (r.sources || 0) + ' sources, ' + (r.chunks || 0) + ' chunks)', 'success');
                }).fail(function () { showMessage($t('Rebuild request failed.'), 'error'); btn.prop('disabled', false); });
        });

        // ---- Recent crawls: collapsed by default, click the heading to expand -
        root.on('click', '[data-role=crawls-toggle]', function (e) {
            e.preventDefault();
            var body = root.find('[data-role=crawls-body]');
            var show = body.css('display') === 'none';
            body.css('display', show ? 'block' : 'none'); // explicit — never leave it to a stylesheet rule
            root.find('[data-role=crawls-caret]').text(show ? '▾' : '▸');
        });

        // ---- Recent crawls: select (checkboxes + select-all) → bulk-delete history ----
        var crawlTable = root.find('[data-role=crawls-body] table.data-grid');
        function crawlCheckedIds() {
            return crawlTable.find('tbody [data-role=crawl-check]:checked').closest('tr')
                .map(function () { return $(this).data('job-id'); }).get()
                .filter(function (s) { return s !== undefined && s !== ''; });
        }
        function refreshCrawlSel() {
            var boxes = crawlTable.find('tbody [data-role=crawl-check]');
            var n = boxes.filter(':checked').length;
            root.find('[data-role=crawl-selcount]').text(n ? n + ' ' + $t('selected') : '');
            root.find('[data-role=crawl-delete-selected]').prop('disabled', n === 0);
            root.find('[data-role=crawl-selectall]').prop('checked', boxes.length > 0 && n === boxes.length);
        }
        root.on('change', '[data-role=crawl-selectall]', function () {
            crawlTable.find('tbody [data-role=crawl-check]').prop('checked', this.checked);
            refreshCrawlSel();
        });
        root.on('change', '[data-role=crawl-check]', refreshCrawlSel);
        root.on('click', '[data-role=crawl-delete-selected]', function () {
            var ids = crawlCheckedIds();
            if (!ids.length) { return; }
            if (!window.confirm($t('Delete the %1 selected crawl(s) from the history? Already-indexed pages are not affected.').replace('%1', ids.length))) { return; }
            $.post(config.crawlDeleteUrl, { form_key: window.FORM_KEY, job_ids: JSON.stringify(ids) })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Delete failed.'), 'error'); return; }
                    reloadWith($t('Removed %1 crawl(s) from the history.').replace('%1', r.deleted || ids.length), 'success');
                }).fail(function () { showMessage($t('Delete request failed.'), 'error'); });
        });

        // ---- crawl a whole site (discover → curate → async ingest) -----------
        var crawlRoot = root.find('[data-role=crawl-root]');
        var crawlConsent = root.find('[data-role=crawl-consent]');
        var crawlStatus = root.find('[data-role=crawl-status]');
        var crawlPicker = root.find('[data-role=crawl-picker]');
        var crawlNote = root.find('[data-role=crawl-note]');
        var crawlListEl = root.find('[data-role=crawl-list]');
        var crawlSelCount = root.find('[data-role=crawl-selcount]');
        var crawlProgress = root.find('[data-role=crawl-progress]');
        var crawlBar = root.find('[data-role=crawl-bar]');
        var crawlProgText = root.find('[data-role=crawl-progress-text]');
        var discovered = { method: '', origin: '', urls: [] };
        var crawlJobId = 0, crawlTimer = null;

        function setCrawlStatus(msg, isError) {
            crawlStatus.text(msg || '').removeClass('msc-sa-status--busy msc-sa-status--error');
            if (msg) { crawlStatus.addClass(isError ? 'msc-sa-status--error' : 'msc-sa-status--busy'); }
        }
        // Ticking consent clears a stale "please tick the box" error.
        root.on('change', '[data-role=crawl-consent]', function () {
            if (crawlConsent.is(':checked')) { setCrawlStatus(''); }
        });

        function selectedUrls() {
            var out = [];
            crawlListEl.find('input[type=checkbox]:checked').each(function () {
                var i = parseInt($(this).attr('data-i'), 10);
                if (discovered.urls[i]) { out.push(discovered.urls[i]); }
            });
            return out;
        }
        function updateSelCount() {
            crawlSelCount.text($t('%1 of %2 selected')
                .replace('%1', selectedUrls().length).replace('%2', discovered.urls.length));
        }
        function renderCrawlList() {
            crawlListEl.empty();
            discovered.urls.forEach(function (u, i) {
                var lbl = $('<label/>');
                $('<input type="checkbox" checked/>').attr('data-i', i).appendTo(lbl);
                $('<span/>').text(u.url).appendTo(lbl);   // textContent — XSS-safe
                crawlListEl.append(lbl);
            });
            updateSelCount();
        }
        root.on('change', '[data-role=crawl-list] input', updateSelCount);
        root.on('click', '[data-role=crawl-all]', function () {
            crawlListEl.find('input[type=checkbox]').prop('checked', true); updateSelCount();
        });
        root.on('click', '[data-role=crawl-none]', function () {
            crawlListEl.find('input[type=checkbox]').prop('checked', false); updateSelCount();
        });
        root.on('input', '[data-role=crawl-filter]', function () {
            var q = $.trim($(this).val()).toLowerCase();
            crawlListEl.find('label').each(function () {
                var t = $(this).find('span').text().toLowerCase();
                $(this).toggleClass('is-hidden', q !== '' && t.indexOf(q) === -1);
            });
        });

        root.on('click', '[data-role=crawl-discover]', function () {
            var rootUrl = $.trim(crawlRoot.val());
            if (!rootUrl) { setCrawlStatus($t('Enter the site URL.'), true); return; }
            if (!crawlConsent.is(':checked')) {
                setCrawlStatus($t('Please tick the permission box before crawling.'), true); return;
            }
            setCrawlStatus($t('Finding pages…'));
            crawlPicker.hide(); crawlProgress.hide();
            $.post(config.discoverUrl, { form_key: window.FORM_KEY, root_url: rootUrl, consent: 1 })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Could not find pages.'), 'error'); setCrawlStatus(''); return; }
                    discovered = { method: r.method, origin: r.origin, urls: r.urls || [] };
                    if (!discovered.urls.length) { setCrawlStatus(r.note || $t('No pages found.'), true); return; }
                    renderCrawlList();
                    crawlNote.text(r.note || '');
                    crawlPicker.show();
                    setCrawlStatus('');
                }).fail(function () { showMessage($t('Discovery request failed.'), 'error'); setCrawlStatus(''); });
        });

        root.on('click', '[data-role=crawl-start]', function () {
            var sel = selectedUrls();
            if (!sel.length) { setCrawlStatus($t('Select at least one page.'), true); return; }
            if (!crawlConsent.is(':checked')) { setCrawlStatus($t('Please tick the permission box first.'), true); return; }
            setCrawlStatus($t('Starting…'));
            $.post(config.crawlStartUrl, {
                form_key: window.FORM_KEY, consent: 1, root_url: $.trim(crawlRoot.val()),
                origin: discovered.origin, method: discovered.method, urls: JSON.stringify(sel)
            }).done(function (r) {
                if (!r || !r.success) { showMessage((r && r.error) || $t('Could not start the crawl.'), 'error'); setCrawlStatus(''); return; }
                crawlJobId = r.job_id; crawlPicker.hide(); setCrawlStatus('');
                crawlProgress.show(); startPolling(r.total);
            }).fail(function () { showMessage($t('Start request failed.'), 'error'); setCrawlStatus(''); });
        });

        function startPolling(total) {
            crawlBar.css('width', '0%');
            crawlProgText.text($t('Queued %1 page(s). The crawl runs in the background — you can leave this page.').replace('%1', total));
            if (crawlTimer) { clearInterval(crawlTimer); }
            crawlTimer = setInterval(pollCrawl, 3000);
            pollCrawl();
        }
        function pollCrawl() {
            if (!crawlJobId) { return; }
            $.post(config.crawlStatusUrl, { form_key: window.FORM_KEY, job_id: crawlJobId })
                .done(function (r) {
                    if (!r || !r.success) { return; }
                    var pct = r.total > 0 ? Math.round(r.processed * 100 / r.total) : 0;
                    crawlBar.css('width', pct + '%');
                    crawlProgText.text($t('%1 of %2 processed — %3 added, %4 skipped, %5 failed.')
                        .replace('%1', r.processed).replace('%2', r.total)
                        .replace('%3', r.done).replace('%4', r.skipped).replace('%5', r.failed));
                    if (r.finished) {
                        clearInterval(crawlTimer); crawlTimer = null;
                        var verb = r.status === 'cancelled' ? $t('Crawl cancelled') : $t('Crawl finished');
                        reloadWith(verb + ' — ' + $t('%1 added, %2 skipped, %3 failed (of %4).')
                            .replace('%1', r.done).replace('%2', r.skipped).replace('%3', r.failed).replace('%4', r.total),
                            r.failed > 0 ? 'warning' : 'success');
                    }
                });
        }
        root.on('click', '[data-role=crawl-cancel]', function () {
            if (!crawlJobId) { return; }
            crawlProgText.text($t('Cancelling…'));
            $.post(config.crawlCancelUrl, { form_key: window.FORM_KEY, job_id: crawlJobId });
        });

        // ---- reusable client-side pager --------------------------------------
        // items: array; renderPage(pageItems, startIndex) draws one page; pagerEl gets the
        // prev/next controls (hidden when a single page).
        function buildPager(items, pagerEl, pageSize, renderPage) {
            var page = 0, pages = Math.max(1, Math.ceil(items.length / pageSize));
            function show() {
                var start = page * pageSize;
                renderPage(items.slice(start, start + pageSize), start);
                pagerEl.empty();
                if (pages <= 1) { pagerEl.attr('hidden', 'hidden'); return; }
                pagerEl.removeAttr('hidden');
                var prev = $('<button type="button" class="action-secondary"/>').text($t('‹ Prev')).prop('disabled', page === 0);
                var next = $('<button type="button" class="action-secondary"/>').text($t('Next ›')).prop('disabled', page >= pages - 1);
                var info = $('<span class="pg-info"/>').text($t('Page %1 of %2').replace('%1', page + 1).replace('%2', pages));
                prev.on('click', function () { if (page > 0) { page--; show(); } });
                next.on('click', function () { if (page < pages - 1) { page++; show(); } });
                pagerEl.append(prev, info, next);
            }
            show();
        }

        // ---- paginate the Knowledge documents grid ---------------------------
        var docRows = root.find('[data-role=doclist] tbody tr').not('[data-role=empty]').toArray();
        if (docRows.length > 15) {
            buildPager(docRows, root.find('[data-role=doclist-pager]'), 15, function (pageRows) {
                docRows.forEach(function (tr) { tr.style.display = 'none'; });
                pageRows.forEach(function (tr) { tr.style.display = ''; });
            });
        }

        // ---- crawl results: load on demand, paginate, download ---------------
        var loadedResults = { title: '', rows: [] };
        function csvCell(v) {
            v = String(v == null ? '' : v);
            return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
        }
        function downloadCsv(text, name) {
            var blob = new Blob([text], { type: 'text/csv' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = name;
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        function renderResults() {
            var body = root.find('[data-role=crawl-results-body]');
            buildPager(loadedResults.rows, root.find('[data-role=crawl-results-pager]'), 25, function (pageRows) {
                body.empty();
                pageRows.forEach(function (u) {
                    var tr = $('<tr class="msc-sa-urlrow"/>');
                    $('<td/>').text(u.url).appendTo(tr);
                    $('<td/>').append($('<span/>').addClass('st-' + u.status).text(u.status)).appendTo(tr);
                    $('<td/>').text(u.chunk_count || '').appendTo(tr);
                    $('<td/>').text(u.reason || '').appendTo(tr);
                    body.append(tr);
                });
            });
        }
        root.on('click', '[data-role=crawl-view]', function () {
            var jobId = $(this).data('job-id');
            var panel = root.find('[data-role=crawl-results]');
            panel.show();
            root.find('[data-role=crawl-results-body]').html($('<tr/>').append($('<td colspan="4"/>').text($t('Loading…'))));
            $.post(config.crawlResultsUrl, { form_key: window.FORM_KEY, job_id: jobId })
                .done(function (r) {
                    if (!r || !r.success) { showMessage((r && r.error) || $t('Could not load results.'), 'error'); return; }
                    loadedResults = { title: r.root_url, rows: r.urls || [] };
                    root.find('[data-role=crawl-results-title]').text($t('%1 — %2 added, %3 skipped, %4 failed of %5')
                        .replace('%1', r.root_url).replace('%2', r.done).replace('%3', r.skipped)
                        .replace('%4', r.failed).replace('%5', r.total));
                    renderResults();
                }).fail(function () { showMessage($t('Results request failed.'), 'error'); });
        });
        root.on('click', '[data-role=crawl-results-close]', function () {
            root.find('[data-role=crawl-results]').hide();
        });
        root.on('click', '[data-role=crawl-download]', function () {
            if (!loadedResults.rows.length) { return; }
            var csv = 'url,result,chunks,note\n' + loadedResults.rows.map(function (u) {
                return [u.url, u.status, u.chunk_count, u.reason || ''].map(csvCell).join(',');
            }).join('\n');
            downloadCsv(csv, 'crawl-results.csv');
        });
    };
});
