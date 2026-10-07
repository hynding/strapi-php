import defaults from './default.json';

export default {
  default: defaults,
  validator(config: { maxActive?: unknown }) {
    const { maxActive } = config;
    if (typeof maxActive !== 'number' || !Number.isInteger(maxActive) || maxActive < 1) {
      throw new Error('maxActive must be a positive integer');
    }
  },
};
