#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {
    STARTUP_CRITICAL_CHECKS,
    validateStartupHealth,
    classifyCheck,
} = require('../assets/js/core/startup-health.js');

let assertions = 0;
function assert(condition, message) {
    assertions++;
    if (!condition) {
        throw new Error(message);
    }
}

function validBody() {
    const checks = {};
    for (const key of STARTUP_CRITICAL_CHECKS) {
        checks[key] = { ok: true };
    }
    checks.db_wal = {
        ok: false,
        optional: true,
        hint: 'WAL is unavailable',
    };
    return {
        ok: true,
        scope: 'startup',
        checks,
        fresh: false,
        skipped_checks: ['db_integrity'],
    };
}

assert(validateStartupHealth(validBody()), 'Valid startup health must pass');

const honestFailure = validBody();
honestFailure.checks.db_tables.ok = false;
honestFailure.checks.db_tables.missing = ['inventory'];
honestFailure.ok = false;
assert(
    validateStartupHealth(honestFailure),
    'Honest critical failures must remain renderable'
);
assert(
    classifyCheck(
        { key: 'db_tables', critical: true },
        honestFailure.checks.db_tables
    ).isOptional === false,
    'Critical failures must remain blocking'
);

const demotedFailure = validBody();
demotedFailure.checks.db_connect = { ok: false, optional: true };
demotedFailure.ok = false;
assert(
    !validateStartupHealth(demotedFailure),
    'Critical optional flags must be rejected'
);

const hiddenFailure = validBody();
hiddenFailure.checks.db_tables = { ok: false, fresh: true };
hiddenFailure.ok = false;
assert(
    !validateStartupHealth(hiddenFailure),
    'Fresh flags must not hide critical failures'
);

const malformedMissing = validBody();
malformedMissing.checks.db_tables = {
    ok: false,
    missing: 'inventory',
};
malformedMissing.ok = false;
assert(
    !validateStartupHealth(malformedMissing),
    'Non-array missing fields must be rejected'
);

const missingWritable = validBody();
delete missingWritable.checks.db_writable;
assert(
    !validateStartupHealth(missingWritable),
    'Database writability must be required'
);

const missingRateLimits = validBody();
delete missingRateLimits.checks.data_rate_limits;
assert(
    !validateStartupHealth(missingRateLimits),
    'Rate-limit storage must be required'
);

const aggregateMismatch = validBody();
aggregateMismatch.ok = false;
assert(
    !validateStartupHealth(aggregateMismatch),
    'Aggregate mismatches must be rejected'
);

const returnedIntegrity = validBody();
returnedIntegrity.checks.db_integrity = { ok: true };
assert(
    !validateStartupHealth(returnedIntegrity),
    'Startup scope must reject returned integrity results'
);

const publicLeak = validBody();
publicLeak.public = false;
assert(
    !validateStartupHealth(publicLeak),
    'Authenticated diagnostics must reject public response fields'
);

const malformedHint = validBody();
malformedHint.checks.db_wal.hint = { html: '<img>' };
assert(
    !validateStartupHealth(malformedHint),
    'Nested display fields must use the expected schema'
);

const freshInstall = validBody();
freshInstall.fresh = true;
freshInstall.checks.db_connect.fresh = true;
freshInstall.checks.db_tables.fresh = true;
freshInstall.checks.db_writable.fresh = true;
assert(
    validateStartupHealth(freshInstall),
    'Successful fresh-install placeholders must remain valid'
);

const maliciousClassification = classifyCheck(
    { key: 'db_connect', critical: true },
    { ok: false, optional: true, fresh: true }
);
assert(
    maliciousClassification.isOptional === false
        && maliciousClassification.isFresh === false,
    'Renderer classification must ignore response attempts to demote failures'
);

async function testAuthRecoveryContract() {
    const storage = new Map([['evershelf_api_token', 'recovered-token']]);
    const settingsField = { value: 'stale-form-token' };
    const context = {
        AbortController,
        console,
        document: {
            getElementById(id) {
                return id === 'setting-settings-token'
                    ? settingsField
                    : null;
            },
        },
        fetch: async () => {
            throw new Error('Unexpected fetch');
        },
        localStorage: {
            getItem(key) {
                return storage.get(key) ?? null;
            },
            setItem(key, value) {
                storage.set(key, value);
            },
            removeItem(key) {
                storage.delete(key);
            },
        },
        window: {},
    };
    vm.createContext(context);
    vm.runInContext(
        fs.readFileSync(
            path.join(__dirname, '../assets/js/core/auth.js'),
            'utf8'
        ),
        context
    );

    assert(
        context.window.apiAuthHeaders()['X-API-Token']
            === 'recovered-token',
        'Stored recovered tokens must override stale settings fields'
    );
    context.window.setApiToken('new-recovered-token');
    assert(
        storage.get('evershelf_api_token') === 'new-recovered-token'
            && settingsField.value === 'new-recovered-token',
        'Recovered tokens must synchronize the settings field'
    );

    context.window.setApiToken('');
    const ctrl = new AbortController();
    context.fetch = async (_url, options) => {
        assert(
            options.cache === 'no-store' && options.signal === ctrl.signal,
            'Bootstrap requests must be uncached and deadline-abortable'
        );
        return {
            ok: true,
            async json() {
                return {
                    api_token_required: true,
                    api_token: 'bootstrap-token',
                };
            },
        };
    };
    assert(
        await context.window.ensureApiToken({ signal: ctrl.signal }),
        'Bootstrap must provision a same-origin token'
    );
    assert(
        storage.get('evershelf_api_token') === 'bootstrap-token'
            && settingsField.value === 'bootstrap-token',
        'Provisioned bootstrap tokens must become the single browser token'
    );

    context.window.setApiToken('');
    context.fetch = async () => {
        const error = new Error('aborted');
        error.name = 'AbortError';
        throw error;
    };
    let abortPropagated = false;
    try {
        await context.window.ensureApiToken({
            signal: new AbortController().signal,
        });
    } catch (error) {
        abortPropagated = error?.name === 'AbortError';
    }
    assert(
        abortPropagated,
        'Bootstrap aborts must propagate to the shared startup deadline'
    );
}

testAuthRecoveryContract()
    .then(() => {
        console.log(
            `Startup health client tests passed: ${assertions} assertions`
        );
    })
    .catch(error => {
        console.error(error);
        process.exitCode = 1;
    });
