// A hand-written config file: data, env calls, a path and an expression the translator cannot follow.
const path = require('path');

module.exports = ({ env }) => ({
  title: 'My ' + env('SITE_NAME', 'site'),
  uploadsDir: path.join(__dirname, '..', env('UPLOADS_DIR', 'public/uploads')),
  limits: { perPage: env.int('PER_PAGE', 25), ratio: 1.5, enabled: env.bool('FEATURE', true), tags: env.array('TAGS', ['a', 'b']) },
  mode: env.oneOf('MODE', ['fast', 'slow'], 'fast'),
  meta: env.json('META'),
  started: env.date('STARTED_AT'),
  empty: {},
  nothing: null,
  computed: env('PORT') === '1337' ? 'default-port' : 'custom-port',
});
