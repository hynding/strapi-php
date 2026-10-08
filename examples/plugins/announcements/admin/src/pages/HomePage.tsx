import { Badge, Box, Flex, Grid, Typography } from '@strapi/design-system';
import { Layouts, Page, useFetchClient } from '@strapi/strapi/admin';
import { useEffect, useState } from 'react';

import { ANNOUNCEMENT_LEVEL, type AnnouncementLevel } from '../generated/content-types';

/** Response of GET /announcements/summary (server/src/routes/admin.json). */
interface Summary {
  total: number;
  active: number;
  byLevel: Record<AnnouncementLevel, number>;
}

const Stat = ({ label, value }: { label: string; value: number }) => (
  <Box background="neutral0" hasRadius shadow="tableShadow" padding={6}>
    <Flex direction="column" alignItems="flex-start" gap={1}>
      <Typography variant="sigma" textColor="neutral600">
        {label}
      </Typography>
      <Typography variant="alpha">{value}</Typography>
    </Flex>
  </Box>
);

const HomePage = () => {
  const { get } = useFetchClient();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    get<{ data: Summary }>('/announcements/summary')
      .then(({ data }) => setSummary(data.data))
      .catch(() => setError(true));
  }, [get]);

  if (error) return <Page.Error />;
  if (!summary) return <Page.Loading />;

  return (
    <Page.Main>
      <Layouts.Header
        title="Announcements"
        subtitle="Manage entries in the Content Manager. The most severe active ones are served at /api/announcements/active."
      />
      <Layouts.Content>
        <Grid.Root gap={4}>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Total" value={summary.total} />
          </Grid.Item>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Active" value={summary.active} />
          </Grid.Item>
        </Grid.Root>
        <Box paddingTop={6}>
          <Flex gap={2} wrap="wrap">
            {[...ANNOUNCEMENT_LEVEL].reverse().map((level) => (
              <Badge key={level}>
                {level}: {summary.byLevel[level]}
              </Badge>
            ))}
          </Flex>
        </Box>
      </Layouts.Content>
    </Page.Main>
  );
};

export { HomePage };
