module.exports = ({ env }) => ({
  url: env('PUBLIC_URL', 'https://example.com'),
  proxy: true,
  cron: { enabled: env.bool('CRON_ENABLED', false) },
});
