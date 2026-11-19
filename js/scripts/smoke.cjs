const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');

// laravel-echo's `require` export (dist/echo.common.js) ships CJS code in a
// `.js` file inside a `"type": "module"` package, so Node cannot require() it.
// Redirect the specifier to its ESM entry — require(esm) is supported by the
// Node versions this package targets (>=22). Bundler consumers are unaffected.
const echoEsm = path.join(path.dirname(require.resolve('laravel-echo')), 'echo.js');
const resolveFilename = Module._resolveFilename;
Module._resolveFilename = function (request, ...args) {
    return request === 'laravel-echo' ? echoEsm : resolveFilename.call(this, request, ...args);
};

const pkg = require('../dist/index.cjs');

const expected = [
    'AppSyncConnector',
    'AppSyncChannel',
    'InvalidChannelNameError',
    'toAppSyncChannel',
    'appSyncEchoOptions',
];

for (const name of expected) {
    assert.ok(pkg[name], `missing CJS export: ${name}`);
}

assert.equal(pkg.toAppSyncChannel('private-orders.1'), '/private/orders/1');
assert.equal(pkg.toAppSyncChannel('orders.1'), '/public/orders/1');

console.log('CJS smoke test passed');
