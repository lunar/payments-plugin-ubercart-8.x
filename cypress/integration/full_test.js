/// <reference types="cypress" />

'use strict';

import { TestMethods } from '../support/test_methods.js';

describe('plugin full test', () => {
    /**
     * Login into admin and frontend to store cookies.
     */
    before(() => {
        cy.goToPage(TestMethods.StoreUrl + '/user/login');
        TestMethods.loginIntoAdminBackend();
    });

    /**
     * Run this on every test case bellow
     * - preserve cookies between tests
     */
    beforeEach(() => {
        Cypress.Cookies.defaults({
            preserve: (cookie) => {
              return true;
            }
        });
    });

    let captureModes = ['Instant', 'Delayed'];
    let currenciesToTest = Cypress.env('ENV_CURRENCIES_TO_TEST');

    /**
     * TEMPORARY ADDED
     */
     it('enable module (disable other)', () => {
        TestMethods.enableThisModuleDisableOther();
    });
    
    context(`make payments in "${captureModes[0]}" mode`, () => {
        /** Modify plugin settings. */
        it(`change capture mode to "${captureModes[0]}"`, () => {
            TestMethods.CaptureMode = captureModes[0];
            TestMethods.changeCaptureMode(captureModes[0]);
        });

        /** Make Instant payments */
        for (var currency of currenciesToTest) {
            TestMethods.changeShopCurrencyFromAdmin(currency);
            TestMethods.payWithSelectedCurrency(currency, 'refund');
        }
    });

    context(`make payments in "${captureModes[1]}" mode`, () => {
        /** Modify plugin settings. */
        it(`change capture mode to "${captureModes[1]}"`, () => {
            TestMethods.CaptureMode = captureModes[1];
            TestMethods.changeCaptureMode(captureModes[1]);
        });

        for (var currency of currenciesToTest) {
            /**
             * HARDCODED currency
             */
            if ('USD' == currency || 'RON' == currency) {
                TestMethods.changeShopCurrencyFromAdmin(currency);

                TestMethods.payWithSelectedCurrency(currency, 'capture');
                /** In "delayed" mode we check "void" action too. */
                TestMethods.payWithSelectedCurrency(currency, 'void');
            }
        }
    });

    /**
     * TEMPORARY ADDED
     */
     it('enable module (disable other)', () => {
        TestMethods.disableThisModuleEnableOther();
    });
}); // describe
