module.exports = (policyContext, config, { strapi }) => policyContext.state.user?.id === config.owner;
