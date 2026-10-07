import type { Core } from '@strapi/strapi';
import { errors } from '@strapi/utils';

import type { Announcement } from '../generated/content-types';
import type { ControllerContract } from '../generated/route-handlers';

const PLUGIN_ID = 'announcements';

/** What the content API exposes of an announcement. */
const toPublic = ({ documentId, title, body, level, createdAt }: Announcement) => ({
  documentId,
  title,
  body,
  level,
  createdAt,
});

export default ({ strapi }: { strapi: Core.Strapi }) =>
  ({
    /** GET /api/announcements/active?limit=n — `limit` defaults to, and is capped by, config `maxActive`. */
    async active(ctx) {
      const max = strapi.plugin(PLUGIN_ID).config('maxActive') as number;
      const raw = ctx.query.limit;

      let limit = max;
      if (raw !== undefined) {
        if (typeof raw !== 'string' || !/^[1-9]\d*$/.test(raw)) {
          throw new errors.ValidationError('limit must be a positive integer', { limit: raw });
        }
        limit = Math.min(Number(raw), max);
      }

      const rows: Announcement[] = await strapi.plugin(PLUGIN_ID).service('announcement').findActive(limit);

      return { data: rows.map(toPublic), meta: { limit } };
    },

    /** GET /announcements/summary (admin) */
    async summary() {
      return { data: await strapi.plugin(PLUGIN_ID).service('announcement').summary() };
    },
  }) satisfies ControllerContract<'announcement'>;
