#!/usr/bin/env node
'use strict';

const {
    normalizeName,
    normalizeCategory,
    createFailureDeferral,
    createCoordinator,
} = require('../assets/js/core/category-refinement.js');

let assertions = 0;
function assert(condition, message) {
    assertions++;
    if (!condition) {
        throw new Error(message);
    }
}

function delay(milliseconds) {
    return new Promise(resolve => setTimeout(resolve, milliseconds));
}

async function main() {
    assert(
        normalizeName('  Daisy  Sour Cream  ') === 'daisy  sour cream',
        'Name normalization must match server trim/lowercase semantics'
    );
    assert(
        normalizeCategory(' ALTRO ') === 'altro'
            && normalizeCategory('unknown') === '',
        'Only explicit supported categories must be accepted'
    );

    const failureDeferral = createFailureDeferral();
    const badges = ['Bad A', 'Bad B', 'Good C'];
    failureDeferral.record('Bad A', 1);
    assert(
        failureDeferral.pick(badges, 1) === 'Bad B',
        'A failed name must not starve later names in the same render'
    );
    failureDeferral.record('Bad B', 1);
    assert(
        failureDeferral.pick(badges, 2) === 'Good C',
        'Never-failed names must outrank deferred retries on later renders'
    );
    assert(
        failureDeferral.pick(['Bad A', 'Bad B'], 2) === 'Bad A',
        'A deferred name must become retryable on a later render'
    );
    failureDeferral.clear(' bad a ');
    assert(
        failureDeferral.pick(['Bad A'], 2) === 'Bad A',
        'A successful normalized name must clear its deferred failure'
    );

    const persistentFailureDeferral = createFailureDeferral();
    const persistentNames = ['Bad A', 'Bad B', 'Bad C', 'Bad D'];
    const persistentAttempts = [];
    for (let generation = 1; generation <= 4; generation++) {
        for (let failure = 0; failure < 2; failure++) {
            const name = persistentFailureDeferral.pick(
                persistentNames,
                generation
            );
            assert(
                typeof name === 'string',
                'Each render must retain an eligible persistent retry'
            );
            persistentAttempts.push(name);
            persistentFailureDeferral.record(name, generation);
        }
    }
    assert(
        JSON.stringify(persistentAttempts) === JSON.stringify([
            'Bad A', 'Bad B',
            'Bad C', 'Bad D',
            'Bad A', 'Bad B',
            'Bad C', 'Bad D',
        ]),
        'Oldest persistent failures must rotate fairly across renders'
    );

    let duplicateCalls = 0;
    const duplicateCoordinator = createCoordinator({
        requestCategory: async () => {
            duplicateCalls++;
            await delay(5);
            return 'latticini';
        },
    });
    const duplicateResults = await Promise.all([
        duplicateCoordinator.get(' Daisy Sour Cream '),
        duplicateCoordinator.get('daisy sour cream'),
        duplicateCoordinator.get('DAISY SOUR CREAM'),
    ]);
    assert(
        duplicateCalls === 1
            && duplicateResults.every(value => value === 'latticini'),
        'Concurrent normalized duplicates must share one request'
    );

    let active = 0;
    let maximumActive = 0;
    const serialCoordinator = createCoordinator({
        requestCategory: async name => {
            active++;
            maximumActive = Math.max(maximumActive, active);
            await delay(5);
            active--;
            return name === 'Fish' ? 'pesce' : 'verdura';
        },
    });
    await Promise.all([
        serialCoordinator.get('Tomato'),
        serialCoordinator.get('Fish'),
        serialCoordinator.get('Carrot'),
    ]);
    assert(
        maximumActive === 1,
        'Category requests must use one shared active worker'
    );

    let altroCalls = 0;
    const altroCoordinator = createCoordinator({
        requestCategory: async () => {
            altroCalls++;
            return 'altro';
        },
    });
    assert(
        await altroCoordinator.get('Unusual item') === 'altro'
            && await altroCoordinator.get(' unusual item ') === 'altro'
            && altroCalls === 1
            && altroCoordinator.hasCached('UNUSUAL ITEM'),
        'A successful explicit altro result must remain session-cached'
    );

    let shouldFail = true;
    const attempted = [];
    const retryCoordinator = createCoordinator({
        requestCategory: async name => {
            attempted.push(name);
            if (shouldFail) {
                throw new Error('temporary failure');
            }
            return 'carne';
        },
    });
    const failed = await Promise.allSettled([
        retryCoordinator.get('Chicken'),
        retryCoordinator.get('Beef'),
    ]);
    assert(
        failed.every(result => result.status === 'rejected')
            && attempted.length === 1,
        'A transient failure must stop and reject the queued cycle'
    );
    shouldFail = false;
    const retried = await Promise.all([
        retryCoordinator.get('Chicken'),
        retryCoordinator.get('Beef'),
    ]);
    assert(
        retried.every(value => value === 'carne')
            && attempted.length === 3,
        'Transient failures must be evicted so a later cycle retries'
    );

    let malformedCalls = 0;
    const malformedCoordinator = createCoordinator({
        requestCategory: async () => {
            malformedCalls++;
            return malformedCalls === 1 ? undefined : 'frutta';
        },
    });
    const malformed = await Promise.allSettled([
        malformedCoordinator.get('Apple'),
    ]);
    assert(
        malformed[0].status === 'rejected'
            && await malformedCoordinator.get('Apple') === 'frutta'
            && malformedCalls === 2,
        'Missing categories must not enter the successful cache'
    );

    console.log(
        `Category refinement client tests passed: ${assertions} assertions`
    );
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
