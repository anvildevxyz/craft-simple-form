"use strict";

// Dependency-free test for the front-end post-submit branch selection (#133).
// It loads the SAME source the browser ships and asserts SF.successAction picks
// the redirect branch when redirectUrl is present, else the inline message.
// Run: node tests/js/success-action.test.js

var assert = require("node:assert");
var path = require("node:path");
var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function ok(label, actual, expected) {
    assert.deepStrictEqual(actual, expected, label);
    passed++;
}

ok(
    "redirect when redirectUrl present",
    SF.successAction({ success: true, message: "Thanks", redirectUrl: "/thanks?e=ada%40example.com" }),
    { action: "redirect", url: "/thanks?e=ada%40example.com" }
);

ok(
    "message when redirectUrl null",
    SF.successAction({ success: true, message: "Thanks Ada!", redirectUrl: null }),
    { action: "message", message: "Thanks Ada!" }
);

ok(
    "message when redirectUrl absent",
    SF.successAction({ success: true, message: "Thanks" }),
    { action: "message", message: "Thanks" }
);

ok(
    "message when redirectUrl empty string",
    SF.successAction({ success: true, message: "Hi", redirectUrl: "" }),
    { action: "message", message: "Hi" }
);

ok(
    "empty message falls back to empty string (showSuccess supplies default)",
    SF.successAction({ success: true }),
    { action: "message", message: "" }
);

console.log("success-action.test.js: " + passed + " assertions passed");
