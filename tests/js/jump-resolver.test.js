"use strict";

// Logic-jump resolution (#245). Loads the SAME source the browser ships and
// asserts SF.jumps.next/reachable match the PHP JumpResolver (mirror of the
// matrix in JumpResolverTest.php) — so the navigator and the server agree on the
// branch taken. Run: node tests/js/jump-resolver.test.js

var assert = require("node:assert");
var path = require("node:path");

var SF = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).SF;

var passed = 0;
function eq(label, actual, expected) {
    assert.deepStrictEqual(actual, expected, label);
    passed++;
}

// 3 steps; step 0 jumps to step 2 when plan = enterprise.
var rules = [
    [{ field: "plan", operator: "eq", value: "enterprise", to: 2 }],
    [],
    []
];

eq("jump taken -> to", SF.jumps.next(rules, 0, { plan: "enterprise" }), 2);
eq("jump not taken -> sequential", SF.jumps.next(rules, 0, { plan: "basic" }), 1);
eq("no rules on step -> sequential", SF.jumps.next(rules, 1, { plan: "enterprise" }), 2);

eq("reachable skips jumped-over step", SF.jumps.reachable(rules, 3, { plan: "enterprise" }), [0, 2]);
eq("reachable linear when no jump", SF.jumps.reachable(rules, 3, { plan: "basic" }), [0, 1, 2]);

// First matching rule wins; later rules are not considered.
var multi = [
    [
        { field: "f", operator: "eq", value: "a", to: 3 },
        { field: "f", operator: "eq", value: "b", to: 2 }
    ],
    [], [], []
];
eq("first match wins", SF.jumps.next(multi, 0, { f: "b" }), 2);
eq("earlier match wins", SF.jumps.next(multi, 0, { f: "a" }), 3);
eq("no match -> sequential", SF.jumps.next(multi, 0, { f: "z" }), 1);
eq("reachable to last via jump", SF.jumps.reachable(multi, 4, { f: "a" }), [0, 3]);

// contains operator (multi-value answer) parity with the conditional engine.
var contains = [[{ field: "tags", operator: "contains", value: "vip", to: 2 }], [], []];
eq("contains member jumps", SF.jumps.next(contains, 0, { tags: ["new", "vip"] }), 2);
eq("contains miss sequential", SF.jumps.next(contains, 0, { tags: ["new"] }), 1);

console.log("jump-resolver.test.js: " + passed + " assertions passed");
