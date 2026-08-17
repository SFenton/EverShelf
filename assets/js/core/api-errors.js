(function (globalScope) {
    'use strict';

    const GENERIC_REQUEST_FAILURE =
        'Request failed. Please try again.';

    function normalizeResponse(data, fallbackMessage = GENERIC_REQUEST_FAILURE) {
        if (!data || typeof data !== 'object') {
            return data;
        }
        const errorCode = data.error;
        if (typeof errorCode !== 'string') {
            return data;
        }

        if (errorCode === 'request_failed') {
            const fallback = typeof fallbackMessage === 'string'
                && fallbackMessage.trim()
                ? fallbackMessage.trim()
                : GENERIC_REQUEST_FAILURE;
            data.error_code = errorCode;
            data.error = fallback;
            return data;
        }

        if (
            /^[a-z][a-z0-9_]*$/.test(errorCode)
            && typeof data.message === 'string'
            && data.message.trim()
        ) {
            data.error_code = errorCode;
            data.error = data.message.trim();
        }
        return data;
    }

    const contract = Object.freeze({
        GENERIC_REQUEST_FAILURE,
        normalizeResponse,
    });

    if (typeof module === 'object' && module.exports) {
        module.exports = contract;
    }
    if (globalScope) {
        globalScope.EverShelfApiErrors = contract;
    }
})(typeof window !== 'undefined' ? window : globalThis);
