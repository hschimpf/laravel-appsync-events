import assert from 'node:assert/strict';
import * as pkg from '../dist/index.js';

const expected = [
    'AppSyncConnector',
    'AppSyncChannel',
    'InvalidChannelNameError',
    'toAppSyncChannel',
    'appSyncEchoOptions',
];

for (const name of expected) {
    assert.ok(pkg[name], `missing ESM export: ${name}`);
}

assert.equal(pkg.toAppSyncChannel('private-orders.1'), '/private/orders/1');
assert.equal(pkg.toAppSyncChannel('orders.1'), '/public/orders/1');

console.log('ESM smoke test passed');
