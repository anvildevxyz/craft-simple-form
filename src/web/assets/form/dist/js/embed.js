/**
 * Simple Form embed loader (#247).
 *
 * Served from `/simple-form/embed.js` and referenced by the copy-paste embed
 * snippets. Finds `[data-sf-embed]` elements and renders the form (from its
 * standalone URL, given in `data-sf-url`) according to `data-sf-mode`:
 *   - inline    : an auto-resizing iframe placed inside the element
 *   - modal     : the element is a trigger that opens a centered overlay
 *   - slide-in  : the element is a trigger that opens a side panel
 *
 * The form inside the iframe runs the unchanged submission pipeline. The styles
 * are injected once (the loader runs on third-party pages, so it can't depend on
 * a stylesheet); colours are deliberately self-contained here.
 */
(function () {
    "use strict";

    if (window.__sfEmbedLoaded) { return; }
    window.__sfEmbedLoaded = true;

    var STYLE_ID = "sf-embed-styles";

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) { return; }
        var css = [
            ".sf-embed-iframe{width:100%;border:0;display:block;background:transparent}",
            ".sf-embed-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:2147483646;opacity:0;transition:opacity .2s ease}",
            ".sf-embed-overlay.sf-embed-open{opacity:1}",
            ".sf-embed-modal{position:relative;background:#fff;width:min(680px,92vw);max-height:90vh;border-radius:8px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.3)}",
            ".sf-embed-modal .sf-embed-iframe{height:80vh}",
            ".sf-embed-panel{position:fixed;top:0;right:0;height:100%;width:min(440px,92vw);background:#fff;z-index:2147483646;box-shadow:-6px 0 30px rgba(0,0,0,.25);transform:translateX(100%);transition:transform .25s ease}",
            ".sf-embed-panel.sf-embed-open{transform:translateX(0)}",
            ".sf-embed-panel .sf-embed-iframe{height:100%}",
            ".sf-embed-close{position:absolute;top:8px;right:8px;z-index:1;width:32px;height:32px;border:0;border-radius:50%;background:rgba(0,0,0,.08);color:#000;font-size:22px;line-height:1;cursor:pointer}",
            ".sf-embed-close:hover{background:rgba(0,0,0,.16)}",
            "@media (prefers-reduced-motion: reduce){.sf-embed-overlay,.sf-embed-panel{transition:none}}"
        ].join("");
        var style = document.createElement("style");
        style.id = STYLE_ID;
        style.textContent = css;
        (document.head || document.documentElement).appendChild(style);
    }

    // Height sync: the standalone page posts {type:'simpleform:height', height}
    // when embedded; resize the matching inline iframe.
    var heightTargets = [];
    window.addEventListener("message", function (e) {
        var data = e.data;
        if (!data || data.type !== "simpleform:height" || typeof data.height !== "number") { return; }
        heightTargets.forEach(function (iframe) {
            if (iframe.contentWindow === e.source) {
                iframe.style.height = Math.max(120, data.height) + "px";
            }
        });
    });

    function makeIframe(url, autoHeight) {
        var iframe = document.createElement("iframe");
        iframe.className = "sf-embed-iframe";
        iframe.src = url;
        iframe.setAttribute("title", "Form");
        iframe.setAttribute("loading", "lazy");
        if (autoHeight) {
            iframe.style.height = "600px";
            heightTargets.push(iframe);
        }
        return iframe;
    }

    function closeButton(onClose) {
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "sf-embed-close";
        btn.setAttribute("aria-label", "Close");
        btn.innerHTML = "&times;";
        btn.addEventListener("click", onClose);
        return btn;
    }

    function openOverlay(url, panel) {
        var host = document.createElement("div");
        host.className = panel ? "sf-embed-panel" : "sf-embed-overlay";

        var container = panel ? host : document.createElement("div");
        if (!panel) {
            container.className = "sf-embed-modal";
            host.appendChild(container);
        }
        container.appendChild(closeButton(function () { dismiss(); }));
        container.appendChild(makeIframe(url, false));

        document.body.appendChild(host);
        requestAnimationFrame(function () { host.classList.add("sf-embed-open"); });

        function dismiss() {
            host.classList.remove("sf-embed-open");
            document.removeEventListener("keydown", onKey);
            setTimeout(function () { if (host.parentNode) { host.parentNode.removeChild(host); } }, 260);
        }
        function onKey(e) { if (e.key === "Escape") { dismiss(); } }
        if (!panel) {
            host.addEventListener("click", function (e) { if (e.target === host) { dismiss(); } });
        }
        document.addEventListener("keydown", onKey);
    }

    function process(el) {
        if (el.getAttribute("data-sf-embed-ready") === "1") { return; }
        el.setAttribute("data-sf-embed-ready", "1");

        var url = el.getAttribute("data-sf-url");
        if (!url) { return; }
        var mode = el.getAttribute("data-sf-mode") || "inline";

        if (mode === "modal" || mode === "slide-in") {
            el.addEventListener("click", function (e) {
                e.preventDefault();
                openOverlay(url, mode === "slide-in");
            });
            return;
        }

        // inline (default): an auto-resizing iframe placed inside the element.
        el.appendChild(makeIframe(url, true));
    }

    function run() {
        injectStyles();
        Array.prototype.forEach.call(document.querySelectorAll("[data-sf-embed]"), process);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", run);
    } else {
        run();
    }
})();
