/**
 * pmmap.js — Promoter Map module (offline edition).
 *
 * Ported from PlantPAN 5 online `promoter_multiple_result.php`.
 * Adaptation notes:
 *   - Both online and offline now post to TFBS_info.php; offline calls
 *     window.ppPostToOnline (defined in promoter_multiple_result.php) which
 *     builds a hidden form and target=_blank submits to the public URL.
 *   - Offline rows don't ship `tfbs_name` / `tf_id` (annotation isolation),
 *     so the per-row meta label shows family only.
 *
 * Usage:
 *   PMmap.init(paneId, {
 *     seq: "ACGT...",
 *     hits: [{ id, family, pos, strand, score, bind }, ...],
 *     dbMode: true,   // link motif IDs to plantpan online
 *   });
 *
 * DOM requirements (rendered by promoter_multiple_result.php):
 *   #<paneId>-pmmap-view      — sequence viewer container
 *   #<paneId>-pmmap-legend    — coloured legend strip
 *   #<paneId>-pmmap-list      — checkbox list of TFBS IDs
 *   #<paneId>-pmmap-filter    — text input (filters the list)
 *   #<paneId>-pmmap-clear     — button (untick everything)
 *   #<paneId>-pmmap-showrev   — checkbox (show reverse strand bases)
 */
window.PMmap = (function () {
    const TXT_H = 18, REV_FULL_H = 16, REV_THIN_H = 9;
    const instances = new Map();

    // ---- Singleton popup (region popup after dragging across a strand) ----
    let popupEl = null;
    function ensurePopup() {
        if (popupEl) return popupEl;
        popupEl = document.createElement("div");
        popupEl.id = "pm-region-popup";
        popupEl.innerHTML = '<span class="pm-pop-close" title="Close">&times;</span><div class="pm-pop-body"></div>';
        document.body.appendChild(popupEl);
        popupEl.querySelector(".pm-pop-close").addEventListener("click", () => {
            popupEl.style.display = "none";
            popupEl._owner && popupEl._owner.clearSelection();
        });
        return popupEl;
    }
    function hidePopup() { if (popupEl) popupEl.style.display = "none"; }

    // ---- Singleton hover tooltip ----------------------------------------
    let tipEl = null;
    function ensureTip() {
        if (tipEl) return tipEl;
        tipEl = document.createElement("div");
        tipEl.className = "pm-tip";
        document.body.appendChild(tipEl);
        return tipEl;
    }
    function showTip(e, html) { const t = ensureTip(); t.innerHTML = html; t.style.opacity = "1"; moveTip(e); }
    function moveTip(e) {
        const t = ensureTip();
        let x = e.pageX + 14, y = e.pageY - 12;
        if (x + 340 > window.scrollX + document.documentElement.clientWidth) x = e.pageX - 350;
        t.style.left = x + "px"; t.style.top = y + "px";
    }
    function hideTip() { if (tipEl) tipEl.style.opacity = "0"; }

    function esc(v) { return String(v == null ? "" : v).replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c])); }

    // Golden-angle palette: deterministic, distinct, scales to many IDs.
    function genColor(i) {
        const hue = (i * 137.50776) % 360;
        const s = 0.52 + 0.30 * (i % 2);
        const l = 0.40 + 0.12 * (Math.floor(i / 2) % 3);
        if (window.d3 && d3.hsl) return d3.hsl(hue, s, l).formatHex();
        // Tiny HSL→hex fallback if d3 isn't loaded for some reason.
        const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((hue / 60) % 2) - 1)), m = l - c / 2;
        let r1 = 0, g1 = 0, b1 = 0;
        if (hue < 60)      { r1 = c; g1 = x; }
        else if (hue<120)  { r1 = x; g1 = c; }
        else if (hue<180)  { g1 = c; b1 = x; }
        else if (hue<240)  { g1 = x; b1 = c; }
        else if (hue<300)  { r1 = x; b1 = c; }
        else               { r1 = c; b1 = x; }
        const f = v => Math.round((v + m) * 255).toString(16).padStart(2, '0');
        return '#' + f(r1) + f(g1) + f(b1);
    }
    function rgba(col, a) {
        if (/^rgb/i.test(col)) { const m = col.match(/[\d.]+/g); return 'rgba(' + m[0] + ',' + m[1] + ',' + m[2] + ',' + a + ')'; }
        let h = String(col).replace('#', ''); if (h.length === 3) h = h.split('').map(c => c + c).join('');
        const n = parseInt(h, 16) || 0; return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function measureCharW() {
        const ruler = document.createElement("span");
        ruler.className = "pm-seq";
        ruler.style.cssText = "position:absolute;visibility:hidden;white-space:pre;left:-9999px;top:-9999px;line-height:18px;";
        ruler.textContent = "ACGT".repeat(25);
        document.body.appendChild(ruler);
        const w = ruler.getBoundingClientRect().width / 100;
        document.body.removeChild(ruler);
        return (w && isFinite(w) && w > 0) ? w : 8;
    }

    function init(paneId, opts) {
        const containerEl = document.getElementById(paneId + "-pmmap-view");
        if (!containerEl) return null;
        opts = opts || {};
        const seq = String(opts.seq || "");
        const dbMode = !!opts.dbMode;
        const comp = (function () {
            const m = { A:'T', T:'A', G:'C', C:'G', a:'t', t:'a', g:'c', c:'g', U:'A', u:'a' };
            let o = ""; for (let i = 0; i < seq.length; i++) o += (m[seq[i]] || seq[i]); return o;
        })();
        // Normalise hits: accept both the offline schema {motif_id, position, strand, score, hit, family}
        // and the online posData schema {tfbs, tfid, name, pos, strand, score, seq, family}.
        const hits = (opts.hits || []).map(d => {
            const pos = parseInt((d.pos != null ? d.pos : d.position), 10);
            const bind = String(d.bind != null ? d.bind : (d.seq != null ? d.seq : (d.hit != null ? d.hit : "")));
            const len = Math.max(1, bind.length || parseInt(d.len, 10) || 1);
            const strand = (String(d.strand || "").indexOf("-") >= 0) ? "-" : "+";
            return { id: String(d.id != null ? d.id : (d.motif_id != null ? d.motif_id : (d.tfbs != null ? d.tfbs : ""))),
                     name: String(d.name || ""), family: String(d.family || ""),
                     tfid: String(d.tfid != null ? d.tfid : (d.tf_id != null ? d.tf_id : "")),
                     pos: pos, len: len, strand: strand, score: d.score, bind: bind };
        }).filter(d => d.id && !isNaN(d.pos) && d.pos >= 1);
        const ids = Array.from(new Set(hits.map(h => h.id))).sort();
        const colorMap = new Map(ids.map((id, i) => [id, genColor(i)]));
        function color(id) { return colorMap.get(id) || "#888888"; }

        const state = { selectedIds: new Set(), activeId: null };
        const drag = { active: false, a: 0, b: 0, strand: "+" };
        let charW = 8, charWMeasured = false;
        let listBuilt = false;
        let skel = null;
        let refreshPending = false;
        function refresh() {
            if (refreshPending) return;
            refreshPending = true;
            requestAnimationFrame(function () {
                refreshPending = false;
                if (!skel) { draw(); return; }
                renderBars();
                renderLegend();
            });
        }

        const hitsById = (function () { const m = {}; hits.forEach(h => { (m[h.id] = m[h.id] || []).push(h); }); return m; })();

        function collectChecked() {
            const listEl = document.getElementById(paneId + "-pmmap-list");
            if (!listEl) return [];
            return Array.from(listEl.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
        }

        function buildList() {
            if (listBuilt) return;
            const listEl = document.getElementById(paneId + "-pmmap-list");
            if (!listEl) return;
            listBuilt = true;
            const frag = document.createDocumentFragment();
            ids.forEach(id => {
                const rowHits = (hitsById[id] || []).slice()
                    .sort((a, b) => (a.pos - b.pos) || (a.strand < b.strand ? -1 : a.strand > b.strand ? 1 : 0));
                const h0 = rowHits[0] || {};

                const row = document.createElement("div");
                row.className = "pmmap-list-item";
                row.dataset.id = id;
                row.dataset.text = (id + " " + (h0.name || "") + " " + (h0.family || "") + " " + (h0.tfid || "")).toLowerCase();

                const lab = document.createElement("label");
                lab.className = "pmmap-list-label";
                const cb = document.createElement("input");
                cb.type = "checkbox"; cb.value = id;
                lab.appendChild(cb);
                if (dbMode) {
                    // Step 26: TFBS_info.php is POST-only — render as a click
                    // target that calls window.ppPostToOnline (defined by the
                    // result page). stopPropagation keeps the parent label /
                    // checkbox from also toggling on the same click.
                    const a = document.createElement("a");
                    a.href = "#";
                    a.className = "pp-matrix-link";
                    a.dataset.matrix = id;
                    a.title = "Open TFBS info on PlantPAN (POST)";
                    a.innerHTML = "&nbsp;<strong>" + esc(id) + "</strong>";
                    a.addEventListener("mousedown", function (e) { e.stopPropagation(); });
                    a.addEventListener("click", function (e) {
                        e.stopPropagation();
                        e.preventDefault();
                        if (window.ppPostToOnline) window.ppPostToOnline(id);
                    });
                    lab.appendChild(a);
                } else {
                    const idsp = document.createElement("strong"); idsp.textContent = " " + id;
                    lab.appendChild(idsp);
                }
                // Offline ships family only (no tfbs_name / tf_id), so the meta is family-only.
                const meta = (h0.name || h0.family) ? (" — " + [h0.name, h0.family].filter(Boolean).join(" / ")) : "";
                if (meta) { const m = document.createElement("span"); m.className = "text-muted"; m.textContent = meta; lab.appendChild(m); }
                const cnt = document.createElement("span");
                cnt.className = "text-muted";
                cnt.textContent = " (" + rowHits.length + (rowHits.length === 1 ? " hit" : " hits") + ")";
                lab.appendChild(cnt);
                row.appendChild(lab);

                let tbl = null;
                function showDetail(on) {
                    if (on && !tbl) {
                        tbl = document.createElement("table");
                        tbl.className = "pmmap-detail";
                        let html = "<tr><th>Position</th><th>Strand</th><th>Score</th><th>Binding Seq</th></tr>";
                        rowHits.forEach(h => {
                            html += "<tr><td>" + h.pos + "</td><td>" + esc(h.strand) + "</td><td>"
                                  + esc(h.score == null ? "" : h.score) + "</td><td><code>" + esc(h.bind) + "</code></td></tr>";
                        });
                        tbl.innerHTML = html;
                        row.appendChild(tbl);
                    }
                    if (tbl) tbl.style.display = on ? "" : "none";
                }

                cb.addEventListener("change", function () {
                    showDetail(cb.checked);
                    state.selectedIds = new Set(collectChecked());
                    state.activeId = null;
                    refresh();
                });

                frag.appendChild(row);
            });
            listEl.appendChild(frag);
        }
        function uncheckAll() {
            const listEl = document.getElementById(paneId + "-pmmap-list");
            if (!listEl) return;
            listEl.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => { cb.checked = false; });
            listEl.querySelectorAll('.pmmap-detail').forEach(t => { t.style.display = "none"; });
        }

        function segments(p1, p2, perLine) {
            p1 = Math.max(1, Math.min(p1, p2)); p2 = Math.min(seq.length, Math.max(p1, p2));
            const out = []; if (p2 < p1) return out;
            const b0 = p1 - 1, b1 = p2 - 1;
            for (let L = Math.floor(b0 / perLine); L <= Math.floor(b1 / perLine); L++) {
                const s = Math.max(b0, L * perLine), e = Math.min(b1, (L + 1) * perLine - 1);
                out.push({ line: L, col0: s - L * perLine, col1: e - L * perLine });
            }
            return out;
        }
        function hitsOverlapping(p1, p2, strand) {
            const lo = Math.min(p1, p2), hi = Math.max(p1, p2);
            return hits.filter(h => h.pos <= hi && (h.pos + h.len - 1) >= lo && (!strand || strand === "*" || h.strand === strand));
        }

        function clearSelection() {
            drag.active = false;
            containerEl.querySelectorAll(".pm-selrange").forEach(n => n.remove());
        }
        function paintSelection(p1, p2, strand, perLine, lineBodies, bandTop, bandH) {
            containerEl.querySelectorAll(".pm-selrange").forEach(n => n.remove());
            segments(Math.min(p1, p2), Math.max(p1, p2), perLine).forEach(seg => {
                const lb = lineBodies[seg.line]; if (!lb) return;
                const d = document.createElement("div");
                d.className = "pm-selrange";
                d.style.left = (seg.col0 * charW) + "px";
                d.style.width = ((seg.col1 - seg.col0 + 1) * charW) + "px";
                d.style.top = bandTop + "px";
                d.style.height = bandH + "px";
                lb.appendChild(d);
            });
        }
        function showRegionPopup(p1, p2, strand, clientX, clientY) {
            const lo = Math.min(p1, p2), hi = Math.max(p1, p2);
            const hh = hitsOverlapping(lo, hi, strand).slice().sort((a, b) => (a.pos - b.pos) || String(a.id).localeCompare(String(b.id)));
            const el = ensurePopup(); el._owner = api;
            const strandTxt = (strand === "-") ? "&minus; (reverse-complement) strand" : "+ (forward) strand";
            let html = '<div style="font-weight:bold;margin-bottom:5px;">Region ' + lo + ' &ndash; ' + hi + ' bp &nbsp;<span style="color:#888;font-weight:normal;">(' + (hi - lo + 1) + ' bp)</span></div>'
                + '<div style="color:#666;margin-bottom:5px;">' + strandTxt + ' &middot; ' + hh.length + ' TF binding site' + (hh.length === 1 ? '' : 's') + '</div>';
            if (hh.length === 0) {
                html += '<div style="color:#888;">No TF binding sites of this strand overlap this region.</div>';
            } else {
                html += '<table><tr><th>Motif ID</th><th>Family</th><th>Pos</th><th>Score</th><th>Seq</th></tr>';
                hh.forEach(h => {
                    html += '<tr><td><b>' + esc(h.id) + '</b></td><td>' + esc(h.family || "—") + '</td>'
                        + '<td>' + h.pos + '&ndash;' + (h.pos + h.len - 1) + '</td><td>' + esc(h.score) + '</td>'
                        + '<td style="font-family:monospace;">' + esc(h.bind) + '</td></tr>';
                });
                html += '</table>';
            }
            el.querySelector(".pm-pop-body").innerHTML = html;
            el.style.display = "block";
            const r = el.getBoundingClientRect();
            const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
            let px = (clientX || 0) + 14, py = (clientY || 0) + 10;
            if (px + r.width + 8 > vw) px = Math.max(4, vw - r.width - 12);
            if (py + r.height + 8 > vh) py = Math.max(4, (clientY || 0) - r.height - 10);
            el.style.left = (px + window.scrollX) + "px";
            el.style.top  = (py + window.scrollY) + "px";
        }

        function renderLegend() {
            const wrap = document.getElementById(paneId + "-pmmap-legend");
            if (!wrap) return;
            wrap.innerHTML = "";
            const shownIds = Array.from(new Set(hits.filter(h => state.selectedIds.has(h.id)).map(h => h.id))).sort();
            const t = document.createElement("span");
            t.style.fontWeight = "600"; t.style.marginRight = "8px";
            if (shownIds.length === 0) { t.textContent = "Tick TFBS in the list on the right to colour them — or drag along a strand to list TFBS in a region."; wrap.appendChild(t); return; }
            if (shownIds.length > 60) {
                t.textContent = shownIds.length + " TFBS highlighted (legend hidden — narrow the filter to see colours).";
                wrap.appendChild(t); return;
            }
            t.textContent = "TFBS (click to focus):";
            wrap.appendChild(t);
            shownIds.forEach(id => {
                const item = document.createElement("span");
                item.className = "pm-legend-item" + (state.activeId && state.activeId !== id ? " pm-dim" : "");
                const sw = document.createElement("span"); sw.className = "pm-legend-swatch"; sw.style.background = color(id);
                const lbl = document.createElement("span"); lbl.textContent = id;
                item.appendChild(sw); item.appendChild(lbl);
                item.addEventListener("click", () => { state.activeId = (state.activeId === id) ? null : id; refresh(); });
                wrap.appendChild(item);
            });
        }

        // Build the wrapped sequence "skeleton" once; bars are repainted by renderBars().
        function buildSkeleton() {
            containerEl.innerHTML = "";
            skel = null;
            if (!seq || seq.length === 0) {
                containerEl.innerHTML = '<div style="padding:16px;color:#888;font-size:.85rem;">No sequence to display.</div>';
                return;
            }
            const revToggle = document.getElementById(paneId + "-pmmap-showrev");
            const revOn = !!(revToggle && revToggle.checked);

            if (!charWMeasured) { charW = measureCharW(); charWMeasured = true; }
            const gutterW = Math.max(40, (String(seq.length).length + 1) * 7);
            const avail = Math.max(120, (containerEl.clientWidth || 700) - gutterW - 24);
            let perLine = Math.max(20, Math.min(Math.floor((avail - charW) / charW), 200));
            if (perLine > seq.length) perLine = seq.length;
            const nLines = Math.ceil(seq.length / perLine);

            const txtH = TXT_H, revH = revOn ? REV_FULL_H : REV_THIN_H;
            const fwdTop = 0, revTop = txtH;
            const lineBlockH = txtH + revH + 5;

            const wrap = document.createElement("div");
            wrap.className = "pm-seq";
            const lineBodies = [];

            for (let L = 0; L < nLines; L++) {
                const start = L * perLine, end = Math.min(seq.length, start + perLine), lineLen = end - start;
                const row = document.createElement("div");
                row.className = "pm-seqline"; row.style.height = lineBlockH + "px";

                const gut = document.createElement("div");
                gut.className = "pm-gutter"; gut.style.flexBasis = gutterW + "px"; gut.textContent = String(start + 1);
                row.appendChild(gut);

                const lb = document.createElement("div");
                lb.className = "pm-linebody"; lb.style.height = lineBlockH + "px";
                lb.dataset.start = start; lb.dataset.len = lineLen;

                const bars = document.createElement("div");
                bars.className = "pm-bars";
                lb.appendChild(bars);

                const txt = document.createElement("div");
                txt.className = "pm-seqtext"; txt.style.height = txtH + "px"; txt.textContent = seq.substring(start, end);
                lb.appendChild(txt);

                const rev = document.createElement("div");
                rev.className = "pm-revtext"; rev.style.height = revH + "px";
                if (revOn) rev.textContent = comp.substring(start, end);
                lb.appendChild(rev);

                row.appendChild(lb); wrap.appendChild(row); lineBodies[L] = lb;
            }
            containerEl.appendChild(wrap);
            skel = { lineBodies: lineBodies, perLine: perLine, txtH: txtH, revH: revH, revTop: revTop, fwdTop: fwdTop };

            function posFromEvent(e) {
                const lb = e.target && e.target.closest ? e.target.closest(".pm-linebody") : null;
                if (!lb) return null;
                const start = +lb.dataset.start, len = +lb.dataset.len;
                const r = lb.getBoundingClientRect();
                let col = Math.floor((e.clientX - r.left) / charW);
                col = Math.max(0, Math.min(col, len - 1));
                const strand = ((e.clientY - r.top) < txtH) ? "+" : "-";
                return { pos: start + col + 1, strand: strand };
            }
            function bandGeom(strand) { return (strand === "-") ? { top: revTop, h: revH } : { top: fwdTop, h: txtH }; }

            wrap.addEventListener("mousemove", function (e) {
                const p = posFromEvent(e); if (!p) { hideTip(); return; }
                if (drag.active) {
                    drag.b = p.pos;
                    const g = bandGeom(drag.strand);
                    paintSelection(drag.a, drag.b, drag.strand, perLine, lineBodies, g.top, g.h);
                    showTip(e, '<b>Selecting:</b> ' + Math.min(drag.a, drag.b) + ' &ndash; ' + Math.max(drag.a, drag.b) + ' bp (' + (Math.abs(drag.b - drag.a) + 1) + ' bp) on the ' + (drag.strand === "-" ? "&minus;" : "+") + ' strand');
                    return;
                }
                let html = '<b>Position:</b> ' + p.pos + ' bp &nbsp;<span style="color:#9fd3ff;">' + ((p.strand === "-" ? comp[p.pos - 1] : seq[p.pos - 1]) || '') + '</span> &nbsp;<span style="color:#888;">' + (p.strand === "-" ? "&minus;" : "+") + ' strand</span>';
                const here = hitsOverlapping(p.pos, p.pos, p.strand);
                if (here.length) {
                    const names = here.slice(0, 6).map(x => esc(x.id)).join(", ");
                    html += '<br><b>TFBS here (' + here.length + '):</b> ' + names + (here.length > 6 ? ', … (drag to list all)' : '');
                }
                showTip(e, html);
            });
            wrap.addEventListener("mouseleave", function () { if (!drag.active) hideTip(); });
            wrap.addEventListener("mousedown", function (e) {
                const p = posFromEvent(e); if (!p) return;
                e.preventDefault(); hidePopup();
                drag.active = true; drag.a = p.pos; drag.b = p.pos; drag.strand = p.strand;
                const g = bandGeom(p.strand);
                paintSelection(p.pos, p.pos, p.strand, perLine, lineBodies, g.top, g.h);
            });
            if (draw._mouseup) document.removeEventListener("mouseup", draw._mouseup);
            draw._mouseup = function (e) {
                if (!drag.active) return;
                drag.active = false; hideTip();
                const a = drag.a, b = drag.b, st = drag.strand;
                if (Math.abs(a - b) < 1) { clearSelection(); return; }
                const g = bandGeom(st);
                paintSelection(a, b, st, perLine, lineBodies, g.top, g.h);
                showRegionPopup(a, b, st, e.clientX, e.clientY);
            };
            document.addEventListener("mouseup", draw._mouseup);
        }

        function renderBars() {
            if (!skel) return;
            const lineBodies = skel.lineBodies, perLine = skel.perLine,
                  txtH = skel.txtH, revH = skel.revH, revTop = skel.revTop, fwdTop = skel.fwdTop;
            const shown = hits.filter(h => state.selectedIds.has(h.id));
            const perLineFrags = lineBodies.map(() => document.createDocumentFragment());
            shown.forEach(h => {
                const c = color(h.id);
                const dim = (state.activeId && h.id !== state.activeId);
                const onMinus = (h.strand === "-");
                segments(h.pos, h.pos + h.len - 1, perLine).forEach(seg => {
                    const frag = perLineFrags[seg.line]; if (!frag) return;
                    const bar = document.createElement("div");
                    bar.className = "pm-bar";
                    bar.style.left = (seg.col0 * charW) + "px";
                    bar.style.width = ((seg.col1 - seg.col0 + 1) * charW) + "px";
                    bar.style.top = (onMinus ? revTop : fwdTop) + "px";
                    bar.style.height = (onMinus ? revH : txtH) + "px";
                    bar.style.background = rgba(c, dim ? 0.10 : 0.42);
                    bar.style[onMinus ? "borderBottom" : "borderTop"] = "2px solid " + (dim ? rgba(c, 0.25) : c);
                    frag.appendChild(bar);
                });
            });
            lineBodies.forEach((lb, L) => {
                const layer = lb.firstElementChild;
                if (layer && layer.classList.contains("pm-bars")) { layer.textContent = ""; layer.appendChild(perLineFrags[L]); }
            });
        }

        function draw() {
            hidePopup(); clearSelection();
            buildSkeleton();
            renderBars();
            renderLegend();
        }

        // Wire toolbar
        const revToggle = document.getElementById(paneId + "-pmmap-showrev");
        if (revToggle) revToggle.addEventListener("change", draw);
        const filterInp = document.getElementById(paneId + "-pmmap-filter");
        if (filterInp) {
            let ft = null;
            filterInp.addEventListener("input", function () {
                clearTimeout(ft);
                ft = setTimeout(function () {
                    const q = filterInp.value.trim().toLowerCase();
                    const listEl = document.getElementById(paneId + "-pmmap-list");
                    if (!listEl) return;
                    listEl.querySelectorAll(".pmmap-list-item").forEach(it => {
                        it.style.display = (q === "" || (it.dataset.text || "").includes(q)) ? "block" : "none";
                    });
                }, 150);
            });
        }
        const clearBtn = document.getElementById(paneId + "-pmmap-clear");
        if (clearBtn) clearBtn.addEventListener("click", function () { uncheckAll(); state.selectedIds = new Set(); state.activeId = null; clearSelection(); hidePopup(); refresh(); });

        // Lazy build: only the first time the Sequence Map tab is shown.
        let built = false;
        function ensureBuilt() {
            if (built) { draw(); return; }
            const listEl = document.getElementById(paneId + "-pmmap-list");
            containerEl.innerHTML = '<div class="pm-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Rendering sequence map…</div>';
            if (listEl) listEl.innerHTML = '<div class="pm-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading TFBS list…</div>';
            requestAnimationFrame(function () { requestAnimationFrame(function () {
                built = true;
                if (listEl) listEl.innerHTML = "";
                buildList();
                draw();
            }); });
        }

        const api = {
            paneId: paneId, redraw: draw, clearSelection: clearSelection,
            buildList: buildList, ensureBuilt: ensureBuilt,
            setSelectedIds: function (a) { state.selectedIds = new Set(a || []); refresh(); }
        };
        instances.set(paneId, api);
        return api;
    }

    // Resize: redraw every *visible* instance (debounced).
    let rt = null;
    window.addEventListener("resize", function () { clearTimeout(rt); rt = setTimeout(function () {
        instances.forEach(a => { const el = document.getElementById(a.paneId + "-pmmap-view"); if (el && el.offsetParent !== null) a.redraw(); });
    }, 250); });
    // A hidden pane has clientWidth 0 → build + measure on first reveal.
    document.addEventListener("shown.bs.tab", function () {
        instances.forEach(a => {
            const el = document.getElementById(a.paneId + "-pmmap-view");
            if (el && el.offsetParent !== null) a.ensureBuilt();
        });
    });

    return { init: init, instances: instances };
})();
