<?php
/**
 * RARL Admin — Shared Layout Helper
 * Provides htmlAdminHead() and adminSidebar()
 */
function htmlAdminHead(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>' . htmlspecialchars($title) . ' — RARL Admin</title>
<meta name="robots" content="noindex,nofollow"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:"class",theme:{extend:{colors:' . brandTailwindConfigJson() . ',fontFamily:{heading:["' . BRAND_FONT_HEADING . '","' . BRAND_FONT_SANS . '","system-ui","sans-serif"]},boxShadow:{card:"0 1px 2px rgba(16,16,16,.04), 0 1px 12px rgba(16,16,16,.05)"}}}}</script>
<link href="' . BRAND_FONT_GOOGLE_URL . '" rel="stylesheet"/>
<link href="' . FONTAWESOME_CDN_URL . '" rel="stylesheet"/>
<style>
  body{font-family:"' . BRAND_FONT_SANS . '",sans-serif;-webkit-font-smoothing:antialiased;}
  h1,h2,h3,h4{font-family:"' . BRAND_FONT_HEADING . '","' . BRAND_FONT_SANS . '",sans-serif;letter-spacing:-0.01em;}
  ' . rarlFontSizeCss() . '
  a,button,[role="button"]{transition:color .15s ease,background-color .15s ease,border-color .15s ease,opacity .15s ease,box-shadow .15s ease;}
  a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:2px solid ' . BRAND_RED . ';outline-offset:2px;border-radius:4px;}
  ::-webkit-scrollbar{width:10px;height:10px;}
  ::-webkit-scrollbar-track{background:transparent;}
  ::-webkit-scrollbar-thumb{background:rgba(120,120,120,.35);border-radius:999px;}
  ::-webkit-scrollbar-thumb:hover{background:rgba(120,120,120,.55);}

  @keyframes rarlFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
  .rarl-admin-content{animation:rarlFadeUp .35s cubic-bezier(.16,1,.3,1)}
  @keyframes rarlSpin{to{transform:rotate(360deg)}}
  .rarl-spinner{display:inline-block;width:1em;height:1em;border:2px solid rgba(255,255,255,.35);border-top-color:currentColor;border-radius:50%;animation:rarlSpin .6s linear infinite;vertical-align:-0.15em;margin-right:.4em;}
  button[disabled] .rarl-btn-label,a[aria-disabled="true"] .rarl-btn-label{opacity:.85}
  .rarl-nav-group-label{font-size:9.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.28);padding:.9rem .75rem .35rem;}
  #admin-sidebar a.rarl-nav-active{position:relative}
  #admin-sidebar a.rarl-nav-active::before{content:"";position:absolute;left:-0.75rem;top:0.4rem;bottom:0.4rem;width:3px;border-radius:0 3px 3px 0;background:' . BRAND_RED . ';}
  #admin-sidebar a{position:relative}
  .rarl-skeleton{background:linear-gradient(90deg,rgba(0,0,0,.06) 25%,rgba(0,0,0,.10) 37%,rgba(0,0,0,.06) 63%);background-size:400% 100%;animation:rarlSkeleton 1.4s ease infinite;border-radius:.5rem;}
  @keyframes rarlSkeleton{0%{background-position:100% 50%}100%{background-position:0 50%}}
  .rarl-dialog{border:0;padding:0;border-radius:1.25rem;box-shadow:0 24px 64px rgba(15,23,42,.28);margin:auto;max-height:90vh;overflow:auto;}
  .rarl-dialog::backdrop{background:rgba(15,23,42,.45);backdrop-filter:blur(2px);}
  .rarl-dialog[open]{animation:rarlPop .18s cubic-bezier(.16,1,.3,1)}
  @keyframes rarlPop{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}
  #rarl-toasts{position:fixed;top:1rem;right:1rem;z-index:100;display:flex;flex-direction:column;gap:.5rem;max-width:min(380px,calc(100vw - 2rem));}
  .rarl-toast{display:flex;align-items:flex-start;gap:.65rem;padding:.75rem .9rem;border-radius:.9rem;font-size:13px;line-height:1.4;background:#111827;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.2);animation:rarlFadeUp .25s cubic-bezier(.16,1,.3,1);}
  .rarl-toast>i{margin-top:2px}
  .rarl-toast span{flex:1}
  .rarl-toast button{opacity:.55;font-size:18px;line-height:1;margin-left:.25rem}
  .rarl-toast button:hover{opacity:1}
  .rarl-toast-success>i{color:#4ade80}.rarl-toast-error{background:#7f1d1d}.rarl-toast-error>i{color:#fca5a5}.rarl-toast-info>i{color:#93c5fd}
  .rarl-toast.out{opacity:0;transform:translateX(12px);transition:all .2s}
  .rarl-file-chip{margin-top:.5rem;display:inline-flex;align-items:center;gap:.4rem;max-width:100%;padding:.3rem .65rem;border-radius:999px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .rarl-has-file{border-color:#10b981 !important;background:#f0fdf4}
  .rarl-drop-active{border-color:' . BRAND_RED . ' !important;background:rgba(225,29,42,.05)}
  th.rarl-sortable{cursor:pointer;user-select:none;white-space:nowrap}
  th.rarl-sortable:hover{color:#111827}
  th.rarl-sortable::after{content:"\2195";opacity:.25;margin-left:.3em;font-size:.95em}
  th.rarl-sortable[data-dir=asc]::after{content:"\2191";opacity:.8}
  th.rarl-sortable[data-dir=desc]::after{content:"\2193";opacity:.8}
  /* ── Admin design system (rarl-*) — shared by rebuilt pages ── */
  body{background:#f6f7f9}
  .rarl-page-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem}
  .rarl-page-head h1{font-size:1.65rem;font-weight:900;color:#0f172a;letter-spacing:-.025em;line-height:1.15}
  .rarl-page-head p{color:#64748b;font-size:.875rem;margin-top:.25rem;max-width:46rem}
  .rarl-card{background:#fff;border:1px solid #e8eaee;border-radius:1.1rem;box-shadow:0 1px 2px rgba(15,23,42,.04),0 4px 16px -8px rgba(15,23,42,.08)}
  .rarl-card-title{font-family:"' . BRAND_FONT_HEADING . '",sans-serif;font-weight:700;font-size:.9rem;color:#0f172a}
  .rarl-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;height:2.4rem;padding:0 1rem;border-radius:.75rem;font-size:.8rem;font-weight:600;color:#1e293b;background:#fff;border:1px solid #e2e8f0;white-space:nowrap;box-shadow:0 1px 2px rgba(15,23,42,.05)}
  .rarl-btn:hover{border-color:#cbd5e1;background:#f8fafc}
  .rarl-btn-primary{background:' . BRAND_RED . ';border-color:' . BRAND_RED . ';color:#fff}
  .rarl-btn-primary:hover{background:' . BRAND_RED_DARK . ';border-color:' . BRAND_RED_DARK . '}
  .rarl-btn-dark{background:#0f172a;border-color:#0f172a;color:#fff}
  .rarl-btn-dark:hover{background:#1e293b;border-color:#1e293b}
  .rarl-btn-sm{height:1.9rem;padding:0 .7rem;font-size:.72rem;border-radius:.6rem}
  .rarl-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.6rem;color:#64748b}
  .rarl-icon-btn:hover{background:#f1f5f9;color:#0f172a}
  .rarl-icon-btn.danger:hover{background:#fef2f2;color:#dc2626}
  .rarl-input{width:100%;height:2.4rem;padding:0 .8rem;border:1px solid #e2e8f0;border-radius:.75rem;font-size:.85rem;background:#fff;color:#0f172a}
  textarea.rarl-input{height:auto;padding:.6rem .8rem}
  .rarl-input:focus{outline:none;border-color:' . BRAND_RED . ';box-shadow:0 0 0 3px rgba(204,7,3,.12)}
  .rarl-label{display:block;font-size:.72rem;font-weight:600;color:#475569;margin-bottom:.35rem}
  .rarl-stat{display:block;background:#fff;border:1px solid #e8eaee;border-radius:1.1rem;padding:1rem 1.1rem;transition:box-shadow .15s,border-color .15s,transform .15s}
  a.rarl-stat:hover{box-shadow:0 8px 24px -12px rgba(15,23,42,.25);transform:translateY(-1px)}
  .rarl-stat.is-active{border-color:' . BRAND_RED . ';box-shadow:0 0 0 3px rgba(204,7,3,.12)}
  .rarl-stat-label{display:flex;align-items:center;gap:.4rem;font-size:.72rem;font-weight:600;color:#64748b}
  .rarl-stat-value{display:block;font-family:"' . BRAND_FONT_HEADING . '",sans-serif;font-weight:900;font-size:1.6rem;line-height:1.1;margin-top:.35rem;color:#0f172a}
  .rarl-table{width:100%;font-size:.8rem}
  .rarl-table thead th{text-align:left;padding:.7rem 1rem;font-size:.66rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;background:#f8fafc;border-bottom:1px solid #eef0f3}
  .rarl-table tbody td{padding:.75rem 1rem;border-bottom:1px solid #f1f3f6;vertical-align:middle}
  .rarl-table tbody tr:last-child td{border-bottom:0}
  .rarl-table tbody tr:hover td{background:#fafbfc}
  .rarl-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:999px;font-size:.68rem;font-weight:700;white-space:nowrap;line-height:1.4}
  .rarl-badge-green{background:#ecfdf5;color:#047857}.rarl-badge-amber{background:#fffbeb;color:#b45309}.rarl-badge-red{background:#fef2f2;color:#b91c1c}
  .rarl-badge-blue{background:#eff6ff;color:#1d4ed8}.rarl-badge-indigo{background:#eef2ff;color:#4338ca}.rarl-badge-purple{background:#faf5ff;color:#7e22ce}.rarl-badge-gray{background:#f1f5f9;color:#475569}
  .rarl-tabs{display:flex;gap:.25rem;padding:.25rem;background:#eef0f3;border-radius:.85rem;overflow-x:auto}
  .rarl-tabs a,.rarl-tabs button{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .85rem;border-radius:.65rem;font-size:.78rem;font-weight:600;color:#475569;white-space:nowrap}
  .rarl-tabs a:hover,.rarl-tabs button:hover{color:#0f172a}
  .rarl-tabs .on{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(15,23,42,.1)}
  .rarl-tabs .count{font-size:.66rem;padding:0 .4rem;border-radius:999px;background:rgba(15,23,42,.07)}
  .rarl-tabs .count.hot{background:' . BRAND_RED . ';color:#fff}
  .rarl-drawer{position:fixed;inset:0 0 0 auto;width:min(560px,100vw);background:#fff;z-index:70;box-shadow:-24px 0 64px rgba(15,23,42,.18);transform:translateX(100%);transition:transform .28s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column}
  .rarl-drawer.open{transform:none}
  .rarl-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:69;opacity:0;pointer-events:none;transition:opacity .2s}
  .rarl-drawer-backdrop.open{opacity:1;pointer-events:auto}
  .rarl-empty{padding:3.5rem 1rem;text-align:center;color:#94a3b8;font-size:.85rem}
  #rarl-queue-pill{position:fixed;bottom:1rem;right:1rem;z-index:90;display:none;align-items:center;gap:.7rem;padding:.6rem .9rem .6rem .7rem;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;box-shadow:0 12px 32px rgba(15,23,42,.3)}
  #rarl-queue-pill .bar{width:90px;height:5px;border-radius:999px;background:rgba(255,255,255,.18);overflow:hidden}
  #rarl-queue-pill .bar>i{display:block;height:100%;background:#4ade80;transition:width .4s}
  .rarl-kbd{font-family:ui-monospace,monospace;font-size:10px;padding:1px 6px;border:1px solid rgba(127,127,127,.35);border-bottom-width:2px;border-radius:5px;}
</style>
</head><body class="bg-gray-100 text-gray-900 min-h-screen">
<script>
  // ── Toasts: rarlToast(msg, "success" | "error" | "info") ──
  window.rarlToast = function(msg, type) {
    type = type || "info";
    var wrap = document.getElementById("rarl-toasts");
    if (!wrap) { wrap = document.createElement("div"); wrap.id = "rarl-toasts"; wrap.setAttribute("aria-live", "polite"); document.body.appendChild(wrap); }
    var icon = {success: "fa-circle-check", error: "fa-triangle-exclamation", info: "fa-circle-info"}[type] || "fa-circle-info";
    var t = document.createElement("div");
    t.className = "rarl-toast rarl-toast-" + type;
    t.innerHTML = "<i class=\"fa-solid " + icon + "\"></i><span></span><button type=\"button\" aria-label=\"Dismiss\">&times;</button>";
    t.querySelector("span").textContent = msg;
    var close = function() { t.classList.add("out"); setTimeout(function() { t.remove(); }, 200); };
    t.querySelector("button").onclick = close;
    wrap.appendChild(t);
    if (type !== "error") setTimeout(close, Math.max(type === "info" ? 2600 : 4200, String(msg).length * 55));
  };

  // ── Styled confirm dialog: rarlConfirm(msg, {ok, danger, title}) → Promise<boolean> ──
  window.rarlConfirm = function(msg, opts) {
    opts = opts || {};
    var danger = opts.danger !== undefined ? opts.danger : /delete|remove|destroy|unlink|cannot be undone|permanent/i.test(msg);
    return new Promise(function(resolve) {
      var d = document.createElement("dialog");
      d.className = "rarl-dialog w-full max-w-sm";
      d.innerHTML = "<div class=\"p-6\"><div class=\"flex gap-4\"><div class=\"w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center " + (danger ? "bg-red-100 text-red-600" : "bg-blue-100 text-blue-600") + "\"><i class=\"fa-solid " + (danger ? "fa-triangle-exclamation" : "fa-circle-question") + "\"></i></div><div><h3 class=\"font-heading font-bold text-gray-900\"></h3><p class=\"text-sm text-gray-600 mt-1 whitespace-pre-line\"></p></div></div><div class=\"flex justify-end gap-2 mt-6\"><button type=\"button\" data-v=\"0\" class=\"px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl\">Cancel</button><button type=\"button\" data-v=\"1\" class=\"px-4 py-2 text-sm font-semibold text-white rounded-xl " + (danger ? "bg-red-600 hover:bg-red-700" : "bg-gray-900 hover:bg-gray-700") + "\"></button></div></div>";
      d.querySelector("h3").textContent = opts.title || (danger ? "Are you sure?" : "Please confirm");
      d.querySelector("p").textContent = msg;
      d.querySelector("[data-v=\"1\"]").textContent = opts.ok || (danger ? "Delete" : "Confirm");
      var done = function(v) { d.close(); d.remove(); resolve(v); };
      d.querySelectorAll("[data-v]").forEach(function(b) { b.onclick = function() { done(b.dataset.v === "1"); }; });
      d.addEventListener("cancel", function(e) { e.preventDefault(); done(false); });
      d.addEventListener("click", function(e) { if (e.target === d) done(false); });
      document.body.appendChild(d); d.showModal();
      d.querySelector("[data-v=\"1\"]").focus();
    });
  };

  // Turn legacy inline `return confirm(...)` handlers into data-confirm so they
  // get the styled dialog instead of the browser native popup.
  function rarlUpgradeConfirms(root) {
    var re = /return\s+confirm\(\s*(["\x27])([\s\S]*?)\1\s*\)\s*;?/;
    root.querySelectorAll("[onsubmit*=\"confirm(\"],[onclick*=\"confirm(\"]").forEach(function(el) {
      ["onsubmit", "onclick"].forEach(function(attr) {
        var code = el.getAttribute(attr); if (!code) return;
        var m = code.match(re); if (!m) return;
        var msg = m[2]; try { msg = Function("return " + m[1] + m[2] + m[1])(); } catch (e) {}
        el.setAttribute("data-confirm", msg);
        var rest = code.replace(re, "").trim();
        if (rest) el.setAttribute(attr, rest); else el.removeAttribute(attr);
      });
    });
  }
  document.addEventListener("DOMContentLoaded", function() { rarlUpgradeConfirms(document); });

  document.addEventListener("click", function(e) {
    var btn = e.target.closest && e.target.closest("button[data-confirm],a[data-confirm],input[type=submit][data-confirm]");
    if (!btn || btn.dataset.confirmed) return;
    e.preventDefault(); e.stopPropagation();
    rarlConfirm(btn.dataset.confirm, {ok: btn.dataset.confirmOk}).then(function(ok) {
      if (!ok) return;
      btn.dataset.confirmed = "1";
      if (btn.tagName === "A") location.href = btn.href; else btn.click();
      setTimeout(function() { delete btn.dataset.confirmed; }, 0);
    });
  }, true);
  document.addEventListener("submit", function(e) {
    var form = e.target;
    if (!form.dataset || !form.dataset.confirm || form.dataset.confirmed) return;
    e.preventDefault(); e.stopImmediatePropagation();
    var submitter = e.submitter;
    rarlConfirm(form.dataset.confirm, {ok: form.dataset.confirmOk}).then(function(ok) {
      if (!ok) return;
      form.dataset.confirmed = "1";
      if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); else form.submit();
      delete form.dataset.confirmed;
    });
  }, true);

  // Global submit-loading feedback: any POST form shows a spinner on its submit
  // button and briefly disables it, so slow admin actions (imports, bulk ops,
  // emails) give visible feedback instead of looking frozen. Opt out per-form
  // with data-no-loading, or per-button with data-no-loading on the button itself.
  document.addEventListener("submit", function(e) {
    if (e.defaultPrevented) return;
    const form = e.target;
    if (form.tagName !== "FORM" || (form.method || "get").toLowerCase() !== "post") return;
    if (form.hasAttribute("data-no-loading")) return;
    const btn = e.submitter && form.contains(e.submitter) ? e.submitter
      : document.activeElement && document.activeElement.type === "submit" && form.contains(document.activeElement)
      ? document.activeElement
      : form.querySelector("button[type=submit]");
    if (!btn || btn.hasAttribute("data-no-loading") || btn.disabled) return;
    if (!btn.dataset.label) btn.dataset.label = btn.innerHTML;
    // Deferred so the clicked button own name/value is still sent with the form.
    setTimeout(function() {
      btn.innerHTML = "<span class=\"rarl-spinner\"></span><span class=\"rarl-btn-label\">Working…</span>";
      btn.disabled = true;
      btn.classList.add("opacity-80", "cursor-wait");
    }, 0);
  });
  // Restore buttons when the page comes back from the back/forward cache.
  window.addEventListener("pageshow", function(e) {
    if (!e.persisted) return;
    document.querySelectorAll("button[data-label]").forEach(function(b) { b.innerHTML = b.dataset.label; b.disabled = false; b.classList.remove("opacity-80", "cursor-wait"); });
  });

  document.addEventListener("DOMContentLoaded", function() {
    // ── File pickers: show the chosen file name + size, highlight on drag ──
    document.querySelectorAll("input[type=file]:not([data-no-chip])").forEach(function(input) {
      var zone = input.parentElement;
      var overlay = getComputedStyle(input).position === "absolute";
      if (overlay) {
        ["dragenter", "dragover"].forEach(function(ev) { input.addEventListener(ev, function() { zone.classList.add("rarl-drop-active"); }); });
        ["dragleave", "drop"].forEach(function(ev) { input.addEventListener(ev, function() { zone.classList.remove("rarl-drop-active"); }); });
      }
      input.addEventListener("change", function() {
        var chip = overlay ? zone.querySelector(":scope > .rarl-file-chip") : (input.nextElementSibling && input.nextElementSibling.classList.contains("rarl-file-chip") ? input.nextElementSibling : null);
        if (!input.files.length) { if (chip) chip.remove(); zone.classList.remove("rarl-has-file"); return; }
        if (!chip) { chip = document.createElement("div"); chip.className = "rarl-file-chip"; (overlay ? zone : input).insertAdjacentElement(overlay ? "beforeend" : "afterend", chip); }
        var files = Array.from(input.files);
        var total = files.reduce(function(s, f) { return s + f.size; }, 0);
        var size = total > 1048576 ? (total / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(total / 1024)) + " KB";
        chip.innerHTML = "<i class=\"fa-solid fa-paperclip\"></i> <span></span>";
        chip.querySelector("span").textContent = (files.length > 1 ? files.length + " files" : files[0].name) + " · " + size;
        if (overlay) zone.classList.add("rarl-has-file");
      });
    });

    // ── Sortable tables: click any text column header to sort ──
    document.querySelectorAll(".rarl-admin-content table").forEach(function(table) {
      var tbody = table.tBodies[0];
      if (!table.tHead || !tbody || table.hasAttribute("data-no-sort")) return;
      var rows = function() { return Array.from(tbody.rows).filter(function(r) { return r.cells.length > 1; }); };
      if (rows().length < 3) return;
      Array.from(table.tHead.rows[0].cells).forEach(function(th, col) {
        if (!th.textContent.trim() || th.querySelector("input,select,button") || /^\s*actions?\s*$/i.test(th.textContent)) return;
        th.classList.add("rarl-sortable"); th.tabIndex = 0; th.title = "Sort";
        var go = function() {
          var dir = th.dataset.dir === "asc" ? "desc" : "asc";
          table.tHead.querySelectorAll("th").forEach(function(o) { delete o.dataset.dir; });
          th.dataset.dir = dir;
          var val = function(r) { var c = r.cells[col]; return c ? (c.dataset.sort || c.innerText).trim() : ""; };
          var sorted = rows().sort(function(a, b) {
            var x = val(a), y = val(b), dx = Date.parse(x), dy = Date.parse(y);
            var nx = parseFloat(x.replace(/[^\d.\-]/g, "")), ny = parseFloat(y.replace(/[^\d.\-]/g, ""));
            var r = /^\d{1,2} [A-Z][a-z]{2} \d{4}/.test(x) && !isNaN(dx) && !isNaN(dy) ? dx - dy
              : /^[\d.,\s%\-]+$/.test(x) && !isNaN(nx) && !isNaN(ny) ? nx - ny
              : x.localeCompare(y, undefined, {numeric: true, sensitivity: "base"});
            return dir === "asc" ? r : -r;
          });
          sorted.forEach(function(r) { tbody.appendChild(r); });
        };
        th.addEventListener("click", go);
        th.addEventListener("keydown", function(e) { if (e.key === "Enter") go(); });
      });
    });
  });

  // ── Command palette (Ctrl/Cmd K): jump to any admin page or action ──
  (function() {
    var dlg, input, list, items = [], active = 0;
    function build() {
      var links = Array.from(document.querySelectorAll("#admin-sidebar nav a")).map(function(a) {
        return {label: a.textContent.trim(), href: a.getAttribute("href"), icon: (a.querySelector("i") || {}).className || "fa-solid fa-arrow-right", group: "Go to"};
      });
      return links.concat([
        {label: "New certificate / ID card template", href: "templates.php#new", icon: "fa-solid fa-pen-ruler", group: "Actions"},
        {label: "Issue certificates", href: "certificates.php", icon: "fa-solid fa-trophy", group: "Actions"},
        {label: "Compose email", href: "compose-email.php", icon: "fa-solid fa-envelope-open-text", group: "Actions"},
        {label: "Open public site", href: "../index.php", icon: "fa-solid fa-earth-americas", group: "Actions"}
      ]);
    }
    function draw() {
      var q = input.value.trim().toLowerCase();
      items = build().filter(function(it) {
        if (!q) return true;
        var s = it.label.toLowerCase(), i = 0;
        for (var n = 0; n < q.length; n++) { i = s.indexOf(q[n], i); if (i < 0) return false; i++; }
        return true;
      });
      if (q) items.sort(function(a, b) { return (b.label.toLowerCase().indexOf(q) === 0) - (a.label.toLowerCase().indexOf(q) === 0); });
      active = Math.min(active, Math.max(0, items.length - 1));
      list.innerHTML = items.length ? "" : "<p class=\"px-4 py-6 text-sm text-gray-400 text-center\">No matches</p>";
      var lastGroup = "";
      items.forEach(function(it, i) {
        if (it.group !== lastGroup) { lastGroup = it.group; var h = document.createElement("p"); h.className = "px-3 pt-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-400"; h.textContent = it.group; list.appendChild(h); }
        var a = document.createElement("a");
        a.href = it.href; a.className = "flex items-center gap-3 px-3 py-2 rounded-lg text-sm " + (i === active ? "bg-rarl-red text-white" : "text-gray-700 hover:bg-gray-100");
        a.innerHTML = "<i class=\"" + it.icon + " w-4 text-center opacity-70\"></i><span></span>";
        a.querySelector("span").textContent = it.label;
        a.onmousemove = function() { if (active !== i) { active = i; draw(); } };
        list.appendChild(a);
      });
      var cur = list.querySelectorAll("a")[active]; if (cur) cur.scrollIntoView({block: "nearest"});
    }
    window.rarlPalette = function() {
      if (!dlg) {
        dlg = document.createElement("dialog");
        dlg.className = "rarl-dialog w-full max-w-lg";
        dlg.style.marginTop = "12vh";
        dlg.innerHTML = "<div class=\"flex items-center gap-3 px-4 border-b border-gray-100\"><i class=\"fa-solid fa-magnifying-glass text-gray-400\"></i><input class=\"flex-1 py-4 text-sm bg-transparent focus:outline-none\" placeholder=\"Jump to a page or action…\" aria-label=\"Search\"/><span class=\"rarl-kbd text-gray-400\">Esc</span></div><div class=\"max-h-[50vh] overflow-y-auto p-2\"></div>";
        input = dlg.querySelector("input"); list = dlg.querySelector("div.overflow-y-auto");
        input.addEventListener("input", function() { active = 0; draw(); });
        input.addEventListener("keydown", function(e) {
          if (e.key === "ArrowDown") { e.preventDefault(); active = Math.min(items.length - 1, active + 1); draw(); }
          if (e.key === "ArrowUp") { e.preventDefault(); active = Math.max(0, active - 1); draw(); }
          if (e.key === "Enter" && items[active]) { e.preventDefault(); location.href = items[active].href; }
        });
        dlg.addEventListener("click", function(e) { if (e.target === dlg) dlg.close(); });
        document.body.appendChild(dlg);
      }
      input.value = ""; active = 0; draw(); dlg.showModal(); input.focus();
    };
    document.addEventListener("keydown", function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); window.rarlPalette(); }
      if (e.key === "/" && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable && !document.querySelector("dialog[open]")) {
        var search = document.querySelector(".rarl-admin-content input[type=search], .rarl-admin-content input[name=q], .rarl-admin-content input[name=search]");
        if (search) { e.preventDefault(); search.focus(); search.select(); }
      }
    });
  })();
</script>';
}

function adminSidebar(string $active = ''): void {
    // Grouped so related tools sit together instead of one flat 16-item list —
    // matches how admins actually think about the platform (people vs. content
    // vs. outreach vs. configuration), and gives each group its own label.
    $groups = [
        'Overview' => [
            'index' => ['index.php', '<i class="fa-solid fa-chart-simple"></i>', 'Dashboard'],
        ],
        'Members & Community' => [
            'members'      => ['members.php',        '<i class="fa-solid fa-users"></i>', 'Members'],
            'import'       => ['import-members.php',  '<i class="fa-solid fa-file-import"></i>', 'Import Members'],
            'community'    => ['community.php',       '<i class="fa-solid fa-comment"></i>', 'Community'],
            'people'       => ['people.php',           '<i class="fa-solid fa-people-group"></i>', 'People'],
            'sections'     => ['sections.php',         '<i class="fa-solid fa-earth-americas"></i>', 'Sections'],
        ],
        'Content & Events' => [
            'events'       => ['events.php',        '<i class="fa-solid fa-calendar-days"></i>', 'Events'],
            'certificates' => ['certificates.php',  '<i class="fa-solid fa-trophy"></i>', 'Certificates'],
            'templates'    => ['templates.php',     '<i class="fa-solid fa-image"></i>', 'Templates'],
            'resources'    => ['resources.php',     '<i class="fa-solid fa-book"></i>', 'Resources'],
        ],
        'Outreach' => [
            'newsletter'   => ['newsletter.php',    '<i class="fa-solid fa-envelope"></i>', 'Newsletter'],
            'compose'      => ['compose-email.php', '<i class="fa-solid fa-envelope-open-text"></i>', 'Compose Email'],
            'email-queue'  => ['email-queue.php',   '<i class="fa-solid fa-paper-plane"></i>', 'Email Queue'],
            'partnerships' => ['partnerships.php',  '<i class="fa-solid fa-handshake"></i>', 'Partnerships'],
        ],
        'Configuration' => [
            'plans'    => ['plans.php',    '<i class="fa-solid fa-graduation-cap"></i>', 'Plans'],
            'migrate'  => ['migrate.php',  '<i class="fa-solid fa-database"></i>', 'Migrations'],
            'settings' => ['settings.php', '<i class="fa-solid fa-gear"></i>', 'Settings'],
        ],
    ];
    echo '<div id="admin-backdrop" onclick="toggleAdminSidebar()" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden"></div>
    <aside id="admin-sidebar" class="w-64 sm:w-56 flex-shrink-0 bg-rarl-navy text-white flex flex-col h-screen fixed top-0 left-0 z-40 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-200">
    <div class="p-4 border-b border-white/10 flex items-center justify-between">
      <div class="flex items-center gap-2 min-w-0">
        <img src="' . BRAND_MARK_PATH . '" alt="RARL" class="w-8 h-8 rounded-lg object-contain flex-shrink-0"/>
        <div class="min-w-0"><div class="font-heading font-bold text-xs leading-tight truncate">RARL Admin</div><div class="text-white/35 text-[9px] truncate">Community Platform</div></div>
      </div>
      <button type="button" onclick="toggleAdminSidebar()" class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white flex-shrink-0" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="px-3 pt-3"><button type="button" onclick="rarlPalette()" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs text-white/50 bg-white/5 hover:bg-white/10 hover:text-white border border-white/10"><i class="fa-solid fa-magnifying-glass"></i><span class="flex-1 text-left">Jump to…</span><span class="rarl-kbd text-white/40">Ctrl K</span></button></div>
    <nav class="flex-1 px-3 pb-3 flex flex-col overflow-y-auto">';
    foreach ($groups as $groupLabel => $items) {
        echo '<div class="rarl-nav-group-label">' . htmlspecialchars($groupLabel) . '</div>';
        foreach ($items as $key => [$href, $icon, $label]) {
            $cls = $key === $active
                ? 'bg-white/15 text-white font-semibold rarl-nav-active'
                : 'text-white/55 hover:bg-white/10 hover:text-white hover:translate-x-0.5';
            echo '<a href="' . $href . '" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs transition-all duration-150 ' . $cls . '">' . $icon . ' ' . $label . '</a>';
        }
    }
    echo '</nav>
    <div class="p-3 border-t border-white/10 space-y-0.5">
      <a href="../index.php" target="_blank" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors"><i class="fa-solid fa-earth-americas"></i> Public Site</a>
      <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
    </aside>
    <script>
      function toggleAdminSidebar() {
        document.getElementById("admin-sidebar").classList.toggle("-translate-x-full");
        document.getElementById("admin-backdrop").classList.toggle("hidden");
      }
    </script>';
}

function adminWrap(callable $content, string $page = '', string $title = ''): void {
    htmlAdminHead($title);
    echo '<div class="flex min-h-screen">';
    adminSidebar($page);
    echo '<main class="flex-1 md:ml-56 min-w-0">';
    echo '<div class="md:hidden sticky top-0 z-20 bg-white border-b border-gray-200 px-4 h-14 flex items-center gap-3">
      <button type="button" onclick="toggleAdminSidebar()" class="w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="font-heading font-bold text-sm text-gray-800">RARL Admin</span>
    </div>';
    echo '<div class="rarl-admin-content p-4 sm:p-7 overflow-auto">';
    $content();
    echo '</div></main></div>';
    adminQueuePill();
    echo '</body></html>';
}

function statCard(string $label, int|string $val, string $icon, string $color, string $href = ''): void {
    $colors = [
        'blue'   => ['bg-blue-50 border-blue-200',   'text-blue-600',   'bg-blue-100'],
        'green'  => ['bg-green-50 border-green-200',  'text-green-600',  'bg-green-100'],
        'amber'  => ['bg-amber-50 border-amber-200',  'text-amber-600',  'bg-amber-100'],
        'red'    => ['bg-red-50 border-red-200',      'text-red-600',    'bg-red-100'],
        'purple' => ['bg-purple-50 border-purple-200','text-purple-600', 'bg-purple-100'],
        'gray'   => ['bg-gray-50 border-gray-200',    'text-gray-600',   'bg-gray-100'],
    ];
    [$bg, $tc, $iconBg] = $colors[$color] ?? $colors['gray'];
    $tag = $href ? "a href=\"{$href}\"" : 'div';
    $cls = $href ? 'hover:-translate-y-0.5 hover:shadow-md cursor-pointer' : '';
    echo "<{$tag} class=\"block bg-white border rounded-2xl p-5 shadow-sm transition-all {$bg} {$cls}\">
    <div class=\"flex items-center justify-between mb-2\">
      <div class=\"w-10 h-10 {$iconBg} rounded-xl flex items-center justify-center text-lg\">{$icon}</div>
    </div>
    <div class=\"font-heading font-black text-2xl text-gray-900\">{$val}</div>
    <div class=\"text-xs {$tc} font-semibold mt-1\">{$label}</div>
</" . ($href ? 'a' : 'div') . ">";
}

// ── Reusable bulk-action toolbar (checkbox column + floating action bar) ──
// Usage per list page:
//   1. echo bulkFormOpen(); right before the table (a detached <form id="bulk-form">
//      the checkboxes/buttons point at via form="bulk-form", so it never nests
//      inside the existing per-row single-action <form> elements in each <td>).
//   2. echo bulkBar([...actions]) where each action is either
//      ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Sure?']
//      or ['label'=>'Export','name'=>'action','value'=>'export_csv','class'=>'...'] for a plain field override.
//   3. Give the <table>'s header row a `<th block start><?= bulkSelectAllCheckbox() th block end and each row
//      `td block <?= bulkRowCheckbox($id) td block end.
//   4. echo bulkBarScript(); once, anywhere after the table.
// The page's own POST handler reads $_POST['ids'] (array) and $_POST['bulk_op'] when action=='bulk'.
// $group distinguishes multiple independent bulk-selection sets on the same
// page (e.g. admin/community.php has separate Posts / Comments / Announcements
// bulk bars) — pass e.g. 'posts' so ids don't collide; omit it for the common
// single-bulk-group-per-page case.
function bulkFormOpen(string $group = '', array $extraFields = []): string {
    $extra = '';
    foreach ($extraFields as $k => $v) $extra .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
    return '<form id="bulk-form' . $group . '" method="POST">' . acsrfField() . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="bulk_op" id="bulk-op' . $group . '">' . $extra . '</form>';
}
function bulkSelectAllCheckbox(string $group = ''): string {
    return '<input type="checkbox" id="select-all' . $group . '" class="accent-rarl-red w-4 h-4"/>';
}
function bulkRowCheckbox(int $id, string $group = ''): string {
    return '<input type="checkbox" class="row-check' . $group . ' accent-rarl-red w-4 h-4" name="ids[]" value="' . $id . '" form="bulk-form' . $group . '"/>';
}
function bulkBar(array $actions, string $group = ''): string {
    $btns = '';
    foreach ($actions as $a) {
        $confirmAttr = !empty($a['confirm'])
            ? " onclick=\"document.getElementById('bulk-op{$group}').value='" . htmlspecialchars($a['op'] ?? '', ENT_QUOTES) . "'; return confirm('" . htmlspecialchars($a['confirm'], ENT_QUOTES) . "');\""
            : (isset($a['op']) ? " onclick=\"document.getElementById('bulk-op{$group}').value='" . htmlspecialchars($a['op'], ENT_QUOTES) . "'\"" : '');
        $nameVal = isset($a['name']) ? ' name="' . htmlspecialchars($a['name']) . '" value="' . htmlspecialchars($a['value'] ?? '') . '"' : '';
        $cls = $a['class'] ?? 'bg-gray-700 hover:bg-gray-600';
        $btns .= '<button type="submit" form="bulk-form' . $group . '"' . $nameVal . $confirmAttr . ' class="px-3 py-1.5 ' . $cls . ' text-xs font-semibold rounded-lg">' . $a['label'] . '</button>';
    }
    return '<div id="bulk-bar' . $group . '" class="hidden sticky top-2 z-20 mb-4 bg-gray-900 text-white rounded-2xl shadow-lg px-5 py-3 flex flex-wrap items-center gap-3">
      <span class="text-sm font-semibold"><span id="bulk-count' . $group . '">0</span> selected</span>
      <div class="flex flex-wrap gap-2 ml-auto">' . $btns . '</div>
    </div>';
}
// Pass every $group used on the page (e.g. bulkBarScript(['', 'comments'])); each
// gets its own independent select-all/checked-count wiring.
function bulkBarScript(array $groups = ['']): string {
    $groupsJson = json_encode($groups);
    return <<<HTML
<script>
  (function() {
    {$groupsJson}.forEach(function(group) {
      const selectAll = document.getElementById('select-all' + group);
      const rowClass = 'row-check' + group;
      const rowChecks = () => Array.from(document.querySelectorAll('.' + rowClass));
      const bulkBarEl = document.getElementById('bulk-bar' + group);
      const bulkCountEl = document.getElementById('bulk-count' + group);
      function syncBulkBar() {
        const checked = rowChecks().filter(c => c.checked);
        if (bulkCountEl) bulkCountEl.textContent = checked.length;
        if (bulkBarEl) bulkBarEl.classList.toggle('hidden', checked.length === 0);
      }
      selectAll?.addEventListener('change', () => { rowChecks().forEach(c => c.checked = selectAll.checked); syncBulkBar(); });
      document.addEventListener('change', (e) => { if (e.target.classList.contains(rowClass)) syncBulkBar(); });
    });
  })();
</script>
HTML;
}

function adminFlash(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return;
    // Shown as a toast (errors stay until dismissed); the noscript banner is a fallback.
    $type = $f['type'] === 'success' ? 'success' : 'error';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){rarlToast(' . json_encode((string)$f["msg"], JSON_HEX_TAG | JSON_HEX_AMP) . ',"' . $type . '");});</script>';
    $cls = $type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-700';
    echo '<noscript><div class="p-4 rounded-xl border mb-5 text-sm ' . $cls . '">' . htmlspecialchars($f['msg']) . '</div></noscript>';
}


// Floating "Sending emails 12/500" pill. While the queue has work, any open
// admin page drives it by ticking admin/email-queue.php — no cron required.
function adminQueuePill(): void {
    try { $sum = emailQueueSummary(); } catch (Throwable $e) { return; }
    $total = 0; $sent = 0;
    foreach ($sum['batches'] as $b) if ($b['pending'] > 0) { $total += $b['total']; $sent += $b['sent'] + $b['failed'] + $b['cancelled']; }
    echo '<a id="rarl-queue-pill" href="email-queue.php" title="Open email queue"><span class="rarl-spinner" style="margin:0"></span><span class="lbl">Sending emails…</span><span class="bar"><i style="width:0"></i></span></a>';
    echo '<script>(function(){var pending=' . (int)$sum['pending'] . ',total=' . (int)$total . ',sent=' . (int)$sent . ',busy=false;var pill=document.getElementById("rarl-queue-pill");
function draw(){if(pending<=0){pill.style.display="none";return;}pill.style.display="flex";pill.querySelector(".lbl").textContent="Sending emails "+sent+"/"+total;pill.querySelector(".bar>i").style.width=(total?Math.round(sent/total*100):0)+"%";}
function tick(){if(busy||pending<=0)return;busy=true;var fd=new FormData();fd.append("action","tick");fd.append("acsrf",' . json_encode($GLOBALS['acsrf'] ?? '') . ');
fetch("email-queue.php",{method:"POST",body:fd,headers:{"X-Requested-With":"fetch"}}).then(function(r){return r.json();}).then(function(d){busy=false;if(!d.ok)return;var t=0,s=0;(d.batches||[]).forEach(function(b){if(+b.pending>0){t+=+b.total;s+=(+b.sent)+(+b.failed)+(+b.cancelled);}});
var was=pending;pending=d.pending;if(t){total=t;sent=s;}else{sent=total;}draw();if(pending>0)setTimeout(tick,800);else if(was>0&&window.rarlToast)rarlToast("All queued emails have been processed","success");}).catch(function(){busy=false;setTimeout(tick,5000);});}
window.rarlQueueKick=function(){pending=Math.max(pending,1);tick();};draw();if(pending>0)setTimeout(tick,600);})();</script>';
}
