module.exports = (plugin) => {
  plugin.controllers.user.me = async (ctx) => { ctx.body = { ok: true }; };
  return plugin;
};
