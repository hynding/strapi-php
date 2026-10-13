import { Badge, Box, Flex, Grid, Table, Tbody, Td, Th, Thead, Tr, Typography } from '@strapi/design-system';
import { Layouts, Page, useFetchClient } from '@strapi/strapi/admin';
import { useEffect, useState } from 'react';

/** Response of GET /runtime-sync/status (server/src/routes/admin.json). */
interface Boot {
  runtime: 'node' | 'php';
  version: string;
  engine: string;
  time: string;
  schemaHash: string;
  plugins: string[];
  contentTypes: number;
}

interface Status {
  runtime: { kind: 'node' | 'php'; version: string; packageVersion: string; engine: string };
  database: {
    client: string;
    schemaHash: string;
    storedHash: string | null;
    status: 'NEW' | 'UNCHANGED' | 'CHANGED';
    tables: { added: string[]; updated: string[]; removed: string[] };
    migrations: { pending: string[]; twinsToLog: string[]; executed: string[] };
    storedBy: { runtime: string; version: string; time: string } | null;
  } | null;
  boots: Partial<Record<'node' | 'php', Boot>>;
  agreement: { sameVersion: boolean | null; sameSchema: boolean | null; samePlugins: boolean | null; pluginsNodeOnly: string[]; pluginsPhpOnly: string[] };
  project: {
    name: string;
    runtimes: Record<'node' | 'php', { present: boolean; version: string | null; installed: boolean }>;
    counts: { paired: number; missingNode: number; missingPhp: number; shared: number };
    files: { facet: string; stem: string; node: string | null; php: string | null; state: 'paired' | 'missing-node' | 'missing-php' }[];
    problems: { level: 'error' | 'warning' | 'info'; code: string; message: string }[];
  };
}

const RUNTIME_LABEL = { node: 'Strapi (Node)', php: 'strapi-php' };

const Stat = ({ label, value, hint }: { label: string; value: string; hint?: string }) => (
  <Box background="neutral0" hasRadius shadow="tableShadow" padding={6}>
    <Flex direction="column" alignItems="flex-start" gap={1}>
      <Typography variant="sigma" textColor="neutral600">
        {label}
      </Typography>
      <Typography variant="beta">{value}</Typography>
      {hint ? <Typography variant="pi" textColor="neutral600">{hint}</Typography> : null}
    </Flex>
  </Box>
);

const yesNo = (value: boolean | null, yes: string, no: string, unknown: string) => (value === null ? unknown : value ? yes : no);

const HomePage = () => {
  const { get } = useFetchClient();
  const [status, setStatus] = useState<Status | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    get<{ data: Status }>('/runtime-sync/status')
      .then(({ data }) => setStatus(data.data))
      .catch(() => setError(true));
  }, [get]);

  if (error) return <Page.Error />;
  if (!status) return <Page.Loading />;

  const { runtime, database, boots, agreement, project } = status;
  const missing = project.files.filter((f) => f.state !== 'paired');

  return (
    <Page.Main>
      <Layouts.Header
        title="Runtime sync"
        subtitle={`${project.name} is served by ${RUNTIME_LABEL[runtime.kind]} ${runtime.version} (${runtime.engine}). Both runtimes can share this database when they agree below.`}
      />
      <Layouts.Content>
        <Grid.Root gap={4}>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Schema" value={database ? database.status : 'n/a'} hint={database ? `hash ${database.schemaHash.slice(0, 12)}${database.storedBy ? `, stored by ${database.storedBy.runtime} ${database.storedBy.version}` : ''}` : undefined} />
          </Grid.Item>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Runtimes agree" value={yesNo(agreement.sameSchema, 'yes', 'NO', 'one side only')} hint={agreement.sameSchema === null ? 'the other runtime has not booted on this database yet' : `version ${yesNo(agreement.sameVersion, 'same', 'differs', '?')}, plugins ${yesNo(agreement.samePlugins, 'same', 'differ', '?')}`} />
          </Grid.Item>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Files paired" value={`${project.counts.paired}`} hint={`${project.counts.missingNode} missing on Node, ${project.counts.missingPhp} missing on PHP, ${project.counts.shared} shared`} />
          </Grid.Item>
          <Grid.Item col={3} s={6} xs={12}>
            <Stat label="Migrations" value={database ? `${database.migrations.pending.length} pending` : 'n/a'} hint={database && database.migrations.twinsToLog.length ? `${database.migrations.twinsToLog.length} twin(s) logged from the other runtime` : undefined} />
          </Grid.Item>
        </Grid.Root>

        <Box paddingTop={6}>
          <Typography variant="delta" tag="h2">
            Last boot per runtime
          </Typography>
          <Box paddingTop={2}>
            <Table colCount={5} rowCount={3}>
              <Thead>
                <Tr>
                  <Th><Typography variant="sigma">Runtime</Typography></Th>
                  <Th><Typography variant="sigma">Version</Typography></Th>
                  <Th><Typography variant="sigma">Booted</Typography></Th>
                  <Th><Typography variant="sigma">Schema hash</Typography></Th>
                  <Th><Typography variant="sigma">Plugins</Typography></Th>
                </Tr>
              </Thead>
              <Tbody>
                {(['node', 'php'] as const).map((kind) => {
                  const boot = boots[kind];
                  return (
                    <Tr key={kind}>
                      <Td><Typography fontWeight={kind === runtime.kind ? 'bold' : undefined}>{RUNTIME_LABEL[kind]}{kind === runtime.kind ? ' (this one)' : ''}</Typography></Td>
                      <Td><Typography>{boot ? `${boot.version} · ${boot.engine}` : '—'}</Typography></Td>
                      <Td><Typography>{boot ? new Date(boot.time).toLocaleString() : 'never on this database'}</Typography></Td>
                      <Td><Typography>{boot ? boot.schemaHash.slice(0, 16) : '—'}</Typography></Td>
                      <Td><Typography>{boot ? `${boot.plugins.length} (${boot.contentTypes} content types)` : '—'}</Typography></Td>
                    </Tr>
                  );
                })}
              </Tbody>
            </Table>
          </Box>
          {agreement.pluginsNodeOnly.length || agreement.pluginsPhpOnly.length ? (
            <Box paddingTop={2}>
              <Typography textColor="warning600">
                Plugins differ: Node only [{agreement.pluginsNodeOnly.join(', ')}], PHP only [{agreement.pluginsPhpOnly.join(', ')}]. A plugin with content types must be enabled on both, or each boot alters the shared database.
              </Typography>
            </Box>
          ) : null}
        </Box>

        <Box paddingTop={6}>
          <Typography variant="delta" tag="h2">
            Project
          </Typography>
          <Box paddingTop={2}>
            <Flex gap={2} wrap="wrap">
              {project.problems.length === 0 ? <Badge active>nothing to do</Badge> : null}
              {project.problems.map((p) => (
                <Badge key={p.code} backgroundColor={p.level === 'error' ? 'danger100' : p.level === 'warning' ? 'warning100' : 'neutral100'} textColor={p.level === 'error' ? 'danger700' : p.level === 'warning' ? 'warning700' : 'neutral700'}>
                  {p.message}
                </Badge>
              ))}
            </Flex>
          </Box>
          {missing.length ? (
            <Box paddingTop={4}>
              <Typography variant="pi" textColor="neutral600">
                Files without a twin (run `strapi-runtime-sync generate` in the project):
              </Typography>
              <Box paddingTop={1}>
                {missing.map((f) => (
                  <Typography key={f.stem} tag="div">
                    {f.state === 'missing-node' ? f.php : f.node} → {f.state === 'missing-node' ? 'Node' : 'PHP'} ({f.facet})
                  </Typography>
                ))}
              </Box>
            </Box>
          ) : null}
        </Box>
      </Layouts.Content>
    </Page.Main>
  );
};

export { HomePage };
