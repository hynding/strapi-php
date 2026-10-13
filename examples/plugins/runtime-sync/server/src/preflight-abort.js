'use strict';

/**
 * Mirrors preflight-abort.php: thrown by the preflight service to stop a boot started with
 * `STRAPI_RUNTIME_SYNC_PREFLIGHT=1`, carrying the report (`strapi-runtime-sync db:check`).
 */
class PreflightAbort extends Error {
  constructor(report) {
    super('runtime-sync preflight: boot aborted on request, nothing was written (STRAPI_RUNTIME_SYNC_PREFLIGHT)');
    this.name = 'PreflightAbort';
    this.report = report;
  }
}

module.exports = { PreflightAbort };
