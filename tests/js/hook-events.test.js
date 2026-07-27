"use strict";

// Dependency-free test for the front-end hook API (#220). It loads the SAME
// source the browser ships and asserts SF.emit dispatches namespaced
// CustomEvents, passes a detail payload, and that a cancelable event aborts when
// a listener calls preventDefault(). Run: node tests/js/hook-events.test.js

var assert = require("node:assert");
var path = require("node:path");

// Minimal CustomEvent polyfill so the browser-only SF.emit runs under Node.
global.CustomEvent = function (type, params) {
    params = params || {};
    this.type = type;
    this.bubbles = !!params.bubbles;
    this.cancelable = !!params.cancelable;
    this.detail = params.detail;
    this.defaultPrevented = false;
};
global.CustomEvent.prototype.preventDefault = function () {
    if (this.cancelable) { this.defaultPrevented = true; }
};

var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function ok(label, actual, expected) {
    assert.deepStrictEqual(actual, expected, label);
    passed++;
}
function truthy(label, actual) {
    assert.ok(actual, label);
    passed++;
}

function makeForm() {
    var listeners = {};
    return {
        addEventListener: function (type, cb) { (listeners[type] = listeners[type] || []).push(cb); },
        dispatchEvent: function (evt) {
            (listeners[evt.type] || []).forEach(function (cb) { cb(evt); });
            return !evt.defaultPrevented;
        }
    };
}

// 1. A non-cancelable emit with no listeners resolves true (not vetoed).
ok("emit returns true with no listeners", SF.emit(makeForm(), "afterSubmit", {}, false), true);

// 2. The dispatched event is namespaced and carries the detail payload.
var seen = null;
var form = makeForm();
form.addEventListener("simpleform:afterSubmit", function (e) { seen = e; });
SF.emit(form, "afterSubmit", { success: true, foo: "bar" }, false);
truthy("afterSubmit listener fired", seen !== null);
ok("event type is namespaced", seen.type, "simpleform:afterSubmit");
ok("detail is passed through", seen.detail, { success: true, foo: "bar" });

// 3. A cancelable event aborts (returns false) when a listener preventDefaults.
var cancelForm = makeForm();
cancelForm.addEventListener("simpleform:beforeSubmit", function (e) { e.preventDefault(); });
ok("cancelable + preventDefault returns false", SF.emit(cancelForm, "beforeSubmit", {}, true), false);

// 4. A cancelable event with a passive listener still proceeds (returns true).
var passiveForm = makeForm();
passiveForm.addEventListener("simpleform:beforeSubmit", function () { /* observe only */ });
ok("cancelable + no preventDefault returns true", SF.emit(passiveForm, "beforeSubmit", {}, true), true);

// 5. preventDefault on a NON-cancelable event is a no-op (cannot veto).
var nonCancelForm = makeForm();
nonCancelForm.addEventListener("simpleform:stepChange", function (e) { e.preventDefault(); });
ok("non-cancelable cannot be vetoed", SF.emit(nonCancelForm, "stepChange", { from: 0, to: 1 }, false), true);

// 6. Guard: emit on a non-dispatchable target is a safe no-op (returns true).
ok("null target is a safe no-op", SF.emit(null, "afterSubmit", {}, true), true);

console.log("hook-events.test.js: " + passed + " assertions passed");
