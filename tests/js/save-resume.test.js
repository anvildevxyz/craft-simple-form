"use strict";

// Resume-link URL builder for "Save & continue later". Loads the SAME source the
// browser ships and asserts SF.resumeUrl preserves any existing query string
// (handle=…, utm_source=…) while merging in / overwriting the sfresume token.
// Regression guard: the old builder used location.origin + location.pathname and
// silently dropped location.search. Run: node tests/js/save-resume.test.js

var assert = require("node:assert");
var path = require("node:path");

var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function eq(label, actual, expected) {
    assert.strictEqual(actual, expected, label);
    passed++;
}

// --- happy path: no existing query string ---------------------------------
eq(
    "adds sfresume when no query string",
    SF.resumeUrl("https://example.com/contact", "abc123"),
    "https://example.com/contact?sfresume=abc123"
);

// --- regression: existing query string must survive (the reported bug) ----
eq(
    "preserves an existing single param (handle)",
    SF.resumeUrl("https://example.com/smoke/simple-form?handle=smokeForm", "abc123"),
    "https://example.com/smoke/simple-form?handle=smokeForm&sfresume=abc123"
);

eq(
    "preserves multiple params (handle + utm) and appends token",
    SF.resumeUrl("https://example.com/contact?handle=smokeForm&utm_source=x", "tok-9"),
    "https://example.com/contact?handle=smokeForm&utm_source=x&sfresume=tok-9"
);

// --- re-save overwrites a stale sfresume rather than duplicating it --------
eq(
    "overwrites an existing sfresume token in place",
    SF.resumeUrl("https://example.com/contact?sfresume=old&utm_source=x", "new"),
    "https://example.com/contact?sfresume=new&utm_source=x"
);

// --- token is URL-encoded --------------------------------------------------
eq(
    "encodes special characters in the token",
    SF.resumeUrl("https://example.com/contact?a=1", "a b/c"),
    "https://example.com/contact?a=1&sfresume=a+b%2Fc"
);

console.log("save-resume.test.js: " + passed + " assertions passed");
