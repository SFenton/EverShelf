(function (globalScope) {
    'use strict';

    const VALID_CATEGORIES = Object.freeze([
        'latticini',
        'carne',
        'pesce',
        'frutta',
        'verdura',
        'pasta',
        'pane',
        'surgelati',
        'bevande',
        'condimenti',
        'snack',
        'conserve',
        'cereali',
        'igiene',
        'pulizia',
        'altro',
    ]);
    const validCategorySet = new Set(VALID_CATEGORIES);

    function normalizeName(value) {
        return typeof value === 'string'
            ? value.trim().toLowerCase()
            : '';
    }

    function normalizeCategory(value) {
        if (typeof value !== 'string') {
            return '';
        }
        const category = value.trim().toLowerCase();
        return validCategorySet.has(category) ? category : '';
    }

    function createFailureDeferral() {
        const failedAtGeneration = new Map();

        return Object.freeze({
            record(name, generation) {
                const key = normalizeName(name);
                if (key) {
                    failedAtGeneration.set(key, generation);
                }
            },
            clear(name) {
                failedAtGeneration.delete(normalizeName(name));
            },
            pick(candidates, generation, getName = value => value) {
                let deferredCandidate = null;
                let deferredGeneration = null;
                for (const candidate of candidates) {
                    const key = normalizeName(getName(candidate));
                    if (!key) {
                        continue;
                    }
                    if (!failedAtGeneration.has(key)) {
                        return candidate;
                    }
                    const failedGeneration = failedAtGeneration.get(key);
                    if (failedGeneration < generation
                        && (
                            deferredGeneration === null
                            || failedGeneration < deferredGeneration
                        )) {
                        deferredCandidate = candidate;
                        deferredGeneration = failedGeneration;
                    }
                }
                return deferredCandidate;
            },
        });
    }

    function createCoordinator({ requestCategory } = {}) {
        if (typeof requestCategory !== 'function') {
            throw new TypeError('requestCategory must be a function');
        }

        const successful = new Map();
        const inFlight = new Map();
        const queue = [];
        let pumping = false;

        function rejectQueued(error) {
            while (queue.length > 0) {
                const entry = queue.shift();
                if (inFlight.get(entry.key) !== entry.promise) {
                    continue;
                }
                inFlight.delete(entry.key);
                entry.reject(error);
            }
        }

        async function pump() {
            if (pumping) {
                return;
            }
            pumping = true;
            try {
                while (queue.length > 0) {
                    const entry = queue.shift();
                    if (inFlight.get(entry.key) !== entry.promise) {
                        continue;
                    }
                    try {
                        const category = normalizeCategory(
                            await requestCategory(entry.name)
                        );
                        if (!category) {
                            throw new Error(
                                'category_refinement_invalid_response'
                            );
                        }
                        successful.set(entry.key, category);
                        inFlight.delete(entry.key);
                        entry.resolve(category);
                    } catch (error) {
                        inFlight.delete(entry.key);
                        entry.reject(error);
                        rejectQueued(error);
                        break;
                    }
                }
            } finally {
                pumping = false;
                if (queue.length > 0) {
                    queueMicrotask(pump);
                }
            }
        }

        function get(name) {
            const requestName = typeof name === 'string' ? name.trim() : '';
            const key = normalizeName(requestName);
            if (!key) {
                return Promise.reject(
                    new Error('category_refinement_name_required')
                );
            }
            if (successful.has(key)) {
                return Promise.resolve(successful.get(key));
            }
            if (inFlight.has(key)) {
                return inFlight.get(key);
            }

            let resolvePromise;
            let rejectPromise;
            const promise = new Promise((resolve, reject) => {
                resolvePromise = resolve;
                rejectPromise = reject;
            });
            const entry = {
                key,
                name: requestName,
                promise,
                resolve: resolvePromise,
                reject: rejectPromise,
            };
            inFlight.set(key, promise);
            queue.push(entry);
            void pump();
            return promise;
        }

        return Object.freeze({
            get,
            hasCached(name) {
                return successful.has(normalizeName(name));
            },
        });
    }

    const contract = Object.freeze({
        VALID_CATEGORIES,
        normalizeName,
        normalizeCategory,
        createFailureDeferral,
        createCoordinator,
    });

    if (typeof module === 'object' && module.exports) {
        module.exports = contract;
    }
    if (globalScope) {
        globalScope.EverShelfCategoryRefinement = contract;
    }
})(typeof window !== 'undefined' ? window : globalThis);
