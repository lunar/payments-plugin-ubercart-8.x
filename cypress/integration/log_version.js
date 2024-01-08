/// <reference types="cypress" />

'use strict';

import { TestMethods } from '../support/test_methods.js';

describe('log versions remotely', () => {
    /**
     * Go to backend site admin
     */
    before(() => {
        cy.goToPage(TestMethods.StoreUrl + '/user/login');
        TestMethods.loginIntoAdminBackend();
    });

    /** Send log after full test finished. */
    it('log shop & plugin versions remotely', () => {
        TestMethods.logVersions();
    });
}); // describe