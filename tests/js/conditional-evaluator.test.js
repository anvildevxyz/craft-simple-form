"use strict";

// Dependency-free parity test for the front-end conditional evaluator. It loads
// the SAME source the browser ships (src/web/assets/form/dist/js/simple-form.js)
// and asserts identical results to the PHP ConditionalEvaluator's test matrix
// (tests/unit/ConditionalEvaluatorTest.php). Run: node tests/js/conditional-evaluator.test.js

var assert = require("node:assert");
var path = require("node:path");
var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function ok(label, actual, expected) {
    assert.strictEqual(actual, expected, label);
    passed++;
}

// --- operator matrix (mirrors ConditionalEvaluatorTest::operatorProvider) ---
var ops = [
    ["eq match", "eq", "us", "us", true],
    ["eq miss", "eq", "ca", "us", false],
    ["eq array member", "eq", ["us", "ca"], "ca", true],
    ["eq array miss", "eq", ["us", "ca"], "mx", false],
    ["eq zero-string", "eq", "0", "0", true],
    ["neq match", "neq", "ca", "us", true],
    ["neq miss", "neq", "us", "us", false],
    ["empty null", "empty", null, "", true],
    ["empty string", "empty", "", "", true],
    ["empty array", "empty", [], "", true],
    ["empty zero NOT empty", "empty", "0", "", false],
    ["empty filled", "empty", "x", "", false],
    ["notEmpty filled", "notEmpty", "x", "", true],
    ["notEmpty blank", "notEmpty", "", "", false],
    ["contains substring", "contains", "hello world", "world", true],
    ["contains miss", "contains", "hello", "world", false],
    ["contains array member", "contains", ["a", "b"], "b", true],
    ["contains array miss", "contains", ["a", "b"], "c", false],
    ["gt numeric true", "gt", "10", "5", true],
    ["gt numeric false", "gt", "3", "5", false],
    ["lt numeric true", "lt", "3", "5", true],
    ["gt non-numeric false", "gt", "abc", "5", false],
    ["gt date true", "gt", "2026-06-15", "2026-01-01", true],
    ["lt date true", "lt", "2026-01-01", "2026-06-15", true],
    ["unknown op never", "bogus", "x", "x", false]
];
ops.forEach(function (c) { ok("compare:" + c[0], SF.compare(c[1], c[2], c[3]), c[4]); });

// --- show / hide + match all/any ---
var showAll = { enabled: true, action: "show", match: "all", rules: [
    { field: "a", operator: "eq", value: "x" },
    { field: "b", operator: "eq", value: "y" }
] };
ok("show all both", SF.isVisible(showAll, { a: "x", b: "y" }), true);
ok("show all one", SF.isVisible(showAll, { a: "x", b: "z" }), false);

var showAny = { enabled: true, action: "show", match: "any", rules: showAll.rules };
ok("show any one", SF.isVisible(showAny, { a: "x", b: "z" }), true);
ok("show any none", SF.isVisible(showAny, { a: "p", b: "z" }), false);

var hide = { enabled: true, action: "hide", match: "all", rules: [{ field: "a", operator: "eq", value: "x" }] };
ok("hide when match", SF.isVisible(hide, { a: "x" }), false);
ok("hide when no match", SF.isVisible(hide, { a: "z" }), true);

// --- defaults / backward compat ---
ok("no block visible", SF.isVisible(null, {}), true);
ok("disabled visible", SF.isVisible({ enabled: false, rules: [{ field: "a", operator: "eq", value: "x" }] }, { a: "z" }), true);
ok("empty rules visible", SF.isVisible({ enabled: true, rules: [] }, {}), true);

// --- unknown target evaluates as empty ---
ok("unknown target eq false", SF.isVisible({ enabled: true, action: "show", rules: [{ field: "missing", operator: "eq", value: "x" }] }, { a: "x" }), false);
ok("unknown target empty true", SF.isVisible({ enabled: true, action: "show", rules: [{ field: "missing", operator: "empty", value: "" }] }, { a: "x" }), true);

// --- conditional required ---
var reqCond = { enabled: true, required: { enabled: true, match: "all", rules: [{ field: "reason", operator: "eq", value: "other" }] } };
ok("req triggers", SF.isRequiredByCondition(reqCond, { reason: "other" }), true);
ok("req not triggered", SF.isRequiredByCondition(reqCond, { reason: "general" }), false);
ok("no req block", SF.isRequiredByCondition({ enabled: true, rules: [] }, {}), false);

console.log("OK — " + passed + " front-end evaluator parity assertions passed");
