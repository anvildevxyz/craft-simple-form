"use strict";

// Dependency-free parity test for the front-end calculation formula engine. It
// loads the SAME source the browser ships (src/web/assets/form/dist/js/simple-form.js)
// and asserts identical results to the PHP Formula helper's test matrix
// (tests/unit/FormulaTest.php). Run: node tests/js/formula.test.js

var assert = require("node:assert");
var path = require("node:path");
var Formula = require(path.join(__dirname, "..", "..", "src", "web", "assets", "form", "dist", "js", "simple-form.js")).Formula;

var passed = 0;
function near(label, actual, expected) {
    assert.ok(Math.abs(actual - expected) < 1e-9, label + " — got " + actual + ", want " + expected);
    passed++;
}
function rejects(label, formula) {
    var threw = false;
    try { Formula.evaluate(formula, { a: 1 }); } catch (e) { threw = true; }
    assert.ok(threw, label + " — should have rejected: " + formula);
    passed++;
}

// --- precedence / associativity (mirrors FormulaTest) ---
near("precedence", Formula.evaluate("2 + 3 * 4", {}), 14);
near("parens", Formula.evaluate("(2 + 3) * 4", {}), 20);
near("left-assoc sub", Formula.evaluate("10 - 4 - 4", {}), 2);
near("nested parens", Formula.evaluate("((2 + 1) * (3 + 3)) * 3", {}), 54);

// --- unary / decimals ---
near("unary neg", Formula.evaluate("-5", {}), -5);
near("double neg", Formula.evaluate("--5", {}), 5);
near("plus neg", Formula.evaluate("5 + -2", {}), 3);
near("decimal div", Formula.evaluate("1.5 / 2", {}), 0.75);
near("leading dot", Formula.evaluate(".5", {}), 0.5);

// --- references ---
near("refs", Formula.evaluate("{quantity} * {unitPrice}", { quantity: 3, unitPrice: 10 }), 30);
near("numeric string ref", Formula.evaluate("{tier}", { tier: "25" }), 25);
near("missing ref zero", Formula.evaluate("{nope} + 1", {}), 1);
near("non-numeric ref zero", Formula.evaluate("{name} * 10", { name: "Alice" }), 0);

// --- functions ---
near("min", Formula.evaluate("min(2, 5, 9)", {}), 2);
near("max", Formula.evaluate("max(2, 5, 9)", {}), 9);
near("round 2dp", Formula.evaluate("round(2.345, 2)", {}), 2.35);
near("round 0dp", Formula.evaluate("round(2.4)", {}), 2);
near("ceil", Formula.evaluate("ceil(2.1)", {}), 3);
near("floor", Formula.evaluate("floor(2.9)", {}), 2);
near("abs", Formula.evaluate("abs(-4)", {}), 4);
near("MAX case-insensitive", Formula.evaluate("MAX(2, 9)", {}), 9);
near("func expr args", Formula.evaluate("round({tier} * (1 + {fees} * 0.05), 0)", { tier: 6.6, fees: 1 }), 7);

// --- divide by zero / empty ---
near("div zero literal", Formula.evaluate("5 / 0", {}), 0);
near("div zero ref", Formula.evaluate("{a} / {b}", { a: 10, b: 0 }), 0);
near("empty formula", Formula.evaluate("", {}), 0);

// --- format ---
assert.strictEqual(Formula.format(30, { decimals: 2, prefix: "CHF " }), "CHF 30.00"); passed++;
assert.strictEqual(Formula.format(1000, { decimals: 0, separator: true }), "1,000"); passed++;
assert.strictEqual(Formula.format(-5, { decimals: 2, prefix: "CHF " }), "CHF -5.00"); passed++;

// --- injection / allow-list rejection (mirrors FormulaTest::rejectProvider) ---
[
    ["php call", "phpinfo()"],
    ["unknown function", "foo(1)"],
    ["power operator", "2 ** 3"],
    ["modulo", "5 % 2"],
    ["unterminated ref", "{handle"],
    ["semicolon", "1; 2"],
    ["brackets", "[1, 2]"],
    ["backtick", "`whoami`"],
    ["string literal", "\"x\""],
    ["bitwise", "1 & 2"],
    ["trailing operator", "1 +"],
    ["double operator", "1 * * 2"],
    ["unbalanced paren", "(1 + 2"],
    ["empty parens", "()"],
    ["invalid ref chars", "{a-b}"],
    ["arity error", "abs(1, 2)"],
    ["dollar var", "$x"]
].forEach(function (c) { rejects(c[0], c[1]); });

console.log("formula.test.js: " + passed + " assertions passed");
