import type { Core } from '@strapi/strapi';

import {
  ANNOUNCEMENT_LEVEL,
  ANNOUNCEMENT_UID,
  type Announcement,
  type AnnouncementLevel,
} from '../generated/content-types';

/** Severity is the position of the level in the schema's enum: info < warning < critical. */
const severity = (level: AnnouncementLevel): number => ANNOUNCEMENT_LEVEL.indexOf(level);

export default ({ strapi }: { strapi: Core.Strapi }) => ({
  /** Active announcements: most severe first, newest first within a level, at most `limit`. */
  async findActive(limit: number): Promise<Announcement[]> {
    const rows = (await strapi.documents(ANNOUNCEMENT_UID).findMany({
      filters: { active: true },
      sort: ['createdAt:desc', 'id:desc'],
    })) as unknown as Announcement[];

    return [...rows].sort((a, b) => severity(b.level) - severity(a.level)).slice(0, limit);
  },

  /** Counts for the admin page. */
  async summary(): Promise<{ total: number; active: number; byLevel: Record<AnnouncementLevel, number> }> {
    const documents = strapi.documents(ANNOUNCEMENT_UID);

    const byLevel = {} as Record<AnnouncementLevel, number>;
    for (const level of ANNOUNCEMENT_LEVEL) {
      byLevel[level] = await documents.count({ filters: { level } });
    }

    return {
      total: await documents.count({}),
      active: await documents.count({ filters: { active: true } }),
      byLevel,
    };
  },
});
