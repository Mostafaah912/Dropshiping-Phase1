'use strict';

let passes = 0;
let failures = 0;

function section(title) {
    console.log('\n=== ' + title + ' ===');
}

function assert(condition, label) {
    if (condition) {
        passes++;
        console.log('  [PASS] ' + label);
    } else {
        failures++;
        console.log('  [FAIL] ' + label);
    }
}

function evidence(label, value) {
    console.log('  [EVIDENCE] ' + label + ':');
    console.log('    ' + JSON.stringify(value, null, 2).split('\n').join('\n    '));
}

function summary(title) {
    console.log('\n=== ' + title + ' ===');
    console.log('PASS: ' + passes);
    console.log('FAIL: ' + failures);
    process.exitCode = failures > 0 ? 1 : 0;
}

module.exports = { section, assert, evidence, summary };
