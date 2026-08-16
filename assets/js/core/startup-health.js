(function (globalScope) {
    'use strict';

    const hasOwn = (value, key) =>
        Object.prototype.hasOwnProperty.call(value, key);

    const STARTUP_CRITICAL_CHECKS = Object.freeze([
        'php_version',
        'ext_pdo_sqlite',
        'ext_curl',
        'ext_json',
        'ext_mbstring',
        'data_dir',
        'data_rate_limits',
        'data_write_test',
        'db_connect',
        'db_tables',
        'db_writable',
    ]);
    const criticalCheckSet = new Set(STARTUP_CRITICAL_CHECKS);

    function isRecord(value) {
        return value !== null
            && typeof value === 'object'
            && !Array.isArray(value);
    }

    function validateCheck(key, check) {
        if (!isRecord(check) || typeof check.ok !== 'boolean') {
            return false;
        }
        if (hasOwn(check, 'optional') && typeof check.optional !== 'boolean') {
            return false;
        }
        if (hasOwn(check, 'fresh') && typeof check.fresh !== 'boolean') {
            return false;
        }
        for (const field of ['error', 'hint']) {
            if (hasOwn(check, field)
                && check[field] !== null
                && typeof check[field] !== 'string') {
                return false;
            }
        }
        if (hasOwn(check, 'value')
            && check.value !== null
            && !['string', 'number', 'boolean'].includes(typeof check.value)) {
            return false;
        }
        if (hasOwn(check, 'missing')
            && (!Array.isArray(check.missing)
                || !check.missing.every(item => typeof item === 'string'))) {
            return false;
        }
        if (criticalCheckSet.has(key)) {
            if (check.optional === true) {
                return false;
            }
            if (check.ok === false && check.fresh === true) {
                return false;
            }
        }
        return true;
    }

    function validateStartupHealth(body) {
        if (!isRecord(body)
            || hasOwn(body, 'public')
            || body.scope !== 'startup'
            || typeof body.ok !== 'boolean'
            || typeof body.fresh !== 'boolean'
            || !Array.isArray(body.skipped_checks)
            || body.skipped_checks.length !== 1
            || body.skipped_checks[0] !== 'db_integrity'
            || !isRecord(body.checks)
            || hasOwn(body.checks, 'db_integrity')) {
            return false;
        }
        if (!Object.entries(body.checks).every(
            ([key, check]) => validateCheck(key, check)
        )) {
            return false;
        }
        if (!STARTUP_CRITICAL_CHECKS.every(
            key => hasOwn(body.checks, key)
        )) {
            return false;
        }
        const criticalChecksOk = STARTUP_CRITICAL_CHECKS.every(
            key => body.checks[key].ok === true
        );
        return body.ok === criticalChecksOk;
    }

    function classifyCheck(definition, check) {
        const isOk = check?.ok === true;
        const isCritical = definition?.critical === true;
        return {
            isOk,
            isOptional: !isCritical,
            isFresh: isOk && check?.fresh === true,
        };
    }

    const contract = Object.freeze({
        STARTUP_CRITICAL_CHECKS,
        validateStartupHealth,
        classifyCheck,
    });

    if (typeof module === 'object' && module.exports) {
        module.exports = contract;
    }
    if (globalScope) {
        globalScope.EverShelfStartupHealth = contract;
    }
})(typeof window !== 'undefined' ? window : globalThis);
