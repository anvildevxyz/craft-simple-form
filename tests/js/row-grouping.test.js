"use strict";

// Dependency-free parity test for the builder's row grouper. It loads the SAME
// source the CP ships (src/web/assets/cp/dist/js/form-builder.js) and asserts
// identical grouping to the PHP FormRows::group() matrix (tests/unit/FormRowsTest.php).
// Run: node tests/js/row-grouping.test.js

var assert = require("node:assert");
var path = require("node:path");
var builder = require(path.join(__dirname, "..", "..", "src", "web", "assets", "cp", "dist", "js", "form-builder.js"));
var groupRows = builder.groupRows;

var passed = 0;
function ok(label, cond) {
    assert.ok(cond, label);
    passed++;
}

function field(name, row) {
    var config = {};
    if (row !== undefined && row !== null) { config.row = row; }
    return { name: name, config: config };
}

function names(rows) {
    return rows.map(function(r) { return r.map(function(f) { return f.name; }); });
}

// No row hints → lone single-column rows (back-compat).
ok("no hints → 3 lone rows",
    JSON.stringify(names(groupRows([field("a"), field("b"), field("c")]))) === JSON.stringify([["a"], ["b"], ["c"]]));

// Adjacent same row → one 2-column row.
ok("adjacent same row joins",
    JSON.stringify(names(groupRows([field("first", 1), field("last", 1), field("email")]))) === JSON.stringify([["first", "last"], ["email"]]));

// Non-adjacent same row value → separate rows (order-driven).
ok("non-adjacent same value splits",
    JSON.stringify(names(groupRows([field("a", 1), field("b", 2), field("c", 1)]))) === JSON.stringify([["a"], ["b"], ["c"]]));

// Cap: a 5th column spills to a new row.
ok("cap spills 5th column",
    JSON.stringify(names(groupRows([field("f1", 1), field("f2", 1), field("f3", 1), field("f4", 1), field("f5", 1)]))) === JSON.stringify([["f1", "f2", "f3", "f4"], ["f5"]]));

// Invalid row values fall back to lone columns; valid pair still groups.
ok("invalid row falls back",
    JSON.stringify(names(groupRows([field("a", 0), field("b", "x"), field("c", 2), field("d", 2)]))) === JSON.stringify([["a"], ["b"], ["c", "d"]]));

// String numeric rows behave like the integer (config round-trips as strings sometimes).
ok("string numeric row groups",
    JSON.stringify(names(groupRows([field("a", "1"), field("b", "1")]))) === JSON.stringify([["a", "b"]]));

ok("MAX_COLUMNS is 4", builder.MAX_COLUMNS === 4);

// ---- column widths (widthOf) ----------------------------------------------
var widthOf = builder.widthOf;
function withWidth(w) { return { config: w === undefined ? {} : { width: w } }; }

ok("no width → 1", widthOf(withWidth()) === 1);
ok("width 2 honoured", widthOf(withWidth(2)) === 2);
ok("string width coerced", widthOf(withWidth("3")) === 3);
ok("width below 1 → 1", widthOf(withWidth(0)) === 1);
ok("width clamped to MAX_COLUMNS", widthOf(withWidth(9)) === builder.MAX_COLUMNS);
ok("invalid width → 1", widthOf(withWidth("x")) === 1);

console.log("row-grouping.test.js: " + passed + " assertions passed");
