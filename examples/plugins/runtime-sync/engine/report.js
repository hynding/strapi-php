'use strict';

/** Mirrors engine/report.php: the text the CLIs print. Same words on both runtimes. */
const badge = (level) => (level === 'error' ? 'ERROR' : level === 'warning' ? 'WARN ' : 'info ');
const pad = (text, width) => text + ' '.repeat(Math.max(0, width - text.length));

function status(rep) {
  const node = rep.runtimes.node;
  const php = rep.runtimes.php;
  const lines = [`runtime-sync status: ${rep.name}`, ''];
  lines.push(`  node  ${node.present ? `present   Strapi ${node.version ?? '?'} (${node.language})${node.installed ? '' : ', node_modules not installed'}` : 'absent'}`);
  lines.push(`  php   ${php.present ? `present   strapi-php ${php.version ?? '?'} (Strapi ${php.upstreamVersion ?? '?'})${php.installed ? '' : ', vendor not installed'}` : 'absent'}`);
  lines.push(`  versions: ${rep.versions.lockstep === null ? 'only one runtime declared' : rep.versions.lockstep ? `in lockstep (${rep.versions.upstream})` : 'DIFFER'}`);
  lines.push('');

  const c = rep.counts;
  lines.push(`files: ${c.paired} paired, ${c.missingNode} missing on node, ${c.missingPhp} missing on php, ${c.shared} shared`);
  for (const file of rep.files) {
    if (file.state === 'paired') continue;
    const have = file.state === 'missing-node' ? file.php : file.node;
    lines.push(`  ${pad(file.state, 12)} ${have}  (${file.facet})`);
  }
  if (rep.unsupported.length > 0) lines.push(`  unsupported  ${rep.unsupported.join(', ')}`);
  lines.push('');

  lines.push(`plugins: node [${rep.plugins.node.join(', ')}], php [${rep.plugins.php.join(', ')}]`);
  lines.push(`database: ${rep.env.databaseClient} (.env)${rep.adminBuild.present ? `, admin build in ${rep.adminBuild.dir}/` : ', no admin build'}`);
  lines.push('');

  if (rep.problems.length === 0) lines.push('ok: nothing to do');
  else for (const p of rep.problems) lines.push(`${badge(p.level)} ${p.message}`);

  return `${lines.join('\n')}\n`;
}

function generate(results, root) {
  const lines = [`runtime-sync generate: ${root}`, ''];
  const review = [];
  for (const r of results) {
    lines.push(`  ${pad(r.action, 12)} ${r.path}${r.notes.length === 0 ? '' : `  (${r.notes.join('; ')})`}`);
    if (r.review) review.push(r.path);
  }
  lines.push('');
  if (review.length > 0) lines.push(`${badge('warning')} review before running: ${review.join(', ')}`);
  const written = results.filter((r) => r.action === 'written' || r.action === 'merged').length;
  lines.push(written > 0 ? `${written} file(s) written. Install the runtime's dependencies next (composer install / npm install), then \`strapi-runtime-sync db:check\`.` : 'nothing written');
  return `${lines.join('\n')}\n`;
}

function database(db) {
  const r = db.runtime;
  const d = db.database;
  const lines = [`runtime-sync db:check on ${r.kind} (Strapi ${r.version}, ${r.engine})`, ''];
  lines.push(`  client:        ${d.client}`);
  lines.push(`  schema hash:   ${d.schemaHash}`);
  lines.push(`  stored hash:   ${d.storedHash ?? '(none: first boot creates the schema)'}${d.storedBy ? ` (stored by ${d.storedBy.runtime} ${d.storedBy.version} at ${d.storedBy.time})` : ''}`);
  lines.push(`  status:        ${d.status}`);
  for (const change of ['added', 'updated', 'removed']) {
    if ((d.tables?.[change] ?? []).length > 0) lines.push(`  tables ${pad(`${change}:`, 8)}${d.tables[change].join(', ')}`);
  }
  lines.push(
    `  migrations:    ${(d.migrations?.pending ?? []).length === 0 ? 'none pending' : `pending ${d.migrations.pending.join(', ')}`}${(d.migrations?.twinsToLog ?? []).length === 0 ? '' : `; already ran on the other runtime, will be logged: ${d.migrations.twinsToLog.join(', ')}`}`
  );
  lines.push('');
  for (const [kind, boot] of Object.entries(db.boots ?? {})) {
    lines.push(`  last boot ${pad(kind, 5)} ${boot.version} ${boot.time}, schema ${String(boot.schemaHash).slice(0, 12)}, ${(boot.plugins ?? []).length} plugin(s)`);
  }
  lines.push('');
  lines.push(
    d.status === 'UNCHANGED'
      ? 'ok: booting this runtime changes nothing in the database'
      : d.status === 'NEW'
        ? `${badge('info')} the database has no schema yet: the first boot creates it`
        : `${badge('warning')} booting this runtime would alter the database: check the tables above are expected (a plugin or content type missing on this side?)`
  );
  return `${lines.join('\n')}\n`;
}

function handoff(result) {
  const lines = [`runtime-sync handoff to ${result.target}`, ''];
  for (const check of result.checks) {
    const mark = check.ok ? 'ok  ' : check.blocking ? 'FAIL' : 'warn';
    lines.push(`  ${mark}  ${pad(check.name, 32)} ${check.detail}`);
  }
  lines.push('');
  lines.push(
    result.ready
      ? `ready: run \`${result.commands.develop}\` (development) or \`${result.commands.start}\` (production); the other runtime can keep running on the same database until you switch traffic`
      : 'not ready: fix the FAIL lines above and run handoff again'
  );
  return `${lines.join('\n')}\n`;
}

module.exports = { badge, status, generate, database, handoff };
