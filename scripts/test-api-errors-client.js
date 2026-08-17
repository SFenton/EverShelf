#!/usr/bin/env node
'use strict';

const {
    GENERIC_REQUEST_FAILURE,
    normalizeResponse,
} = require('../assets/js/core/api-errors.js');

let assertions = 0;
function assert(condition, message) {
    assertions++;
    if (!condition) {
        throw new Error(message);
    }
}

const rawFailure = normalizeResponse({
    success: false,
    error: 'request_failed',
    message: 'SQLSTATE table inventory at /var/www/html/api/index.php',
}, 'Something went wrong.');
assert(
    rawFailure.error === 'Something went wrong.'
        && rawFailure.error_code === 'request_failed'
        && !rawFailure.error.includes('SQLSTATE')
        && !rawFailure.error.includes('/var/www'),
    'Raw request diagnostics must never become public client messages'
);

const defaultFailure = normalizeResponse({
    success: false,
    error: 'request_failed',
    message: 'private detail',
}, '');
assert(
    defaultFailure.error === GENERIC_REQUEST_FAILURE,
    'Request failures must retain a safe built-in fallback'
);

const busy = normalizeResponse({
    success: false,
    error: 'database_busy',
    message: 'EverShelf is briefly busy. Retry this request safely.',
});
assert(
    busy.error === 'EverShelf is briefly busy. Retry this request safely.'
        && busy.error_code === 'database_busy',
    'Curated machine-coded messages must remain readable'
);

const legacy = normalizeResponse({
    success: false,
    error: 'Product ID required',
    message: 'unused',
});
assert(
    legacy.error === 'Product ID required'
        && !Object.hasOwn(legacy, 'error_code'),
    'Legacy human-readable errors must remain unchanged'
);

assert(
    normalizeResponse(null) === null,
    'Non-object responses must pass through unchanged'
);

console.log(
    `API error client tests passed: ${assertions} assertions`
);
