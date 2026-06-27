"use strict";

// Pure step/conversational-screen navigation logic (#239). Loads the SAME source
// the browser ships and asserts SF.stepNav skips conditionally-hidden screens,
// recomputes progress, and exposes the right Back/Next/Submit state — including
// reaching the end. Run: node tests/js/step-nav.test.js

var assert = require("node:assert");
var path = require("node:path");

var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function eq(label, actual, expected) {
    assert.deepStrictEqual(actual, expected, label);
    passed++;
}

// --- forward / back across all-active steps -------------------------------
var all = [true, true, true];
eq("next from 0", SF.stepNav.seek(all, 0, 1), 1);
eq("next from 1", SF.stepNav.seek(all, 1, 1), 2);
eq("next past end", SF.stepNav.seek(all, 2, 1), -1);
eq("back from 2", SF.stepNav.seek(all, 2, -1), 1);
eq("back past start", SF.stepNav.seek(all, 0, -1), -1);

// --- conditional skip: middle screen hidden -------------------------------
var skip = [true, false, true]; // screen 1 conditionally hidden
eq("next skips hidden", SF.stepNav.seek(skip, 0, 1), 2);
eq("back skips hidden", SF.stepNav.seek(skip, 2, -1), 0);

// --- progress recompute counts only active screens ------------------------
var s0 = SF.stepNav.state(skip, 0);
eq("progress pos at first active", s0.pos, 1);
eq("progress total excludes hidden", s0.total, 2);
var s2 = SF.stepNav.state(skip, 2);
eq("progress pos at second active", s2.pos, 2);

// --- button state: first / middle / reach-end -----------------------------
var first = SF.stepNav.state(all, 0);
eq("first: no back", first.back, false);
eq("first: has next", first.next, true);
eq("first: no submit", first.submit, false);

var middle = SF.stepNav.state(all, 1);
eq("middle: back + next", [middle.back, middle.next, middle.submit], [true, true, false]);

var end = SF.stepNav.state(all, 2);
eq("end: back, no next, submit", [end.back, end.next, end.submit], [true, false, true]);

// reach-end when the last screen is conditionally hidden → the prior active
// screen is the effective last (shows Submit).
var endHidden = [true, true, false];
var s1 = SF.stepNav.state(endHidden, 1);
eq("effective last shows submit", [s1.next, s1.submit], [false, true]);

// --- progress template interpolation --------------------------------------
eq("progress label", SF.stepNav.progress("Question {current} of {total}", 2, 3), "Question 2 of 3");
eq("progress fallback", SF.stepNav.progress(null, 1, 4), "1 of 4");

console.log("step-nav.test.js: " + passed + " assertions passed");
