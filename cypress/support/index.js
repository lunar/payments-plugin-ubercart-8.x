// ***********************************************************
// This example support/index.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
import './commands'

// Alternatively you can use CommonJS syntax:
// require('./commands')

/**
 * TEMPORARY ADDED
 * *
 * We want to get over some errors, not all of them.
 */
 Cypress.on('uncaught:exception', (err, runnable) => {
    /**
     * we expect the following error and don't want to fail the test so we return false
     * "Script error" is the description provided by Cypress, not the actual console error message
     *
    */
    if (err.message.includes('Script error')) {
      return false
    }
    /** if other libraries don't load, we skip these errors. */
    // if (err.message.includes("Cannot read properties of undefined (reading 'substring')")) {
    //   return false
    // }
    /**
     * we still want to ensure there are no other unexpected
     * errors, so we let them fail the test
     */
});