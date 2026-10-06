// Runs upstream metadata + metadataToSchema on the getstarted schemas (the way core does) and dumps the target schema.
import { readFileSync, writeFileSync, readdirSync } from 'fs';
import { join, basename, dirname } from 'path';
import _ from 'lodash';
import { createMetadata } from '/tmp/strapi/packages/core/database/src/metadata';
import { metadataToSchema } from '/tmp/strapi/packages/core/database/src/schema/schema';
import { identifiers } from '/tmp/strapi/packages/core/database/src/utils/identifiers';
import { transformContentTypesToModels } from '/tmp/strapi/packages/core/core/src/utils/transform-content-types-to-models';

const root = '/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted';

function contentType(json: any, uid: string, pluginName?: string) {
  const schema = _.cloneDeep(json);
  const modelName = schema.info.singularName;
  Object.assign(schema, {
    uid, modelType: 'contentType', kind: schema.kind || 'collectionType', modelName,
    collectionName: schema.collectionName || (pluginName ? `${pluginName}_${modelName}`.toLowerCase() : modelName),
    globalId: _.upperFirst(_.camelCase(pluginName ? `${pluginName}-${modelName}` : modelName)),
    plugin: pluginName,
  });
  schema.options = schema.options || {};
  if (!_.has(schema, 'options.draftAndPublish')) schema.options.draftAndPublish = false;
  schema.attributes.createdAt = { type: 'datetime' };
  schema.attributes.updatedAt = { type: 'datetime' };
  schema.attributes.publishedAt = { type: 'datetime', configurable: false, writable: true, visible: true, default: () => new Date() };
  const isPrivate = !_.get(schema, 'options.populateCreatorFields', false);
  for (const f of ['createdBy', 'updatedBy']) {
    schema.attributes[f] = { type: 'relation', relation: 'oneToOne', target: 'admin::user', configurable: false, writable: false, visible: false, useJoinTable: false, private: isPrivate };
  }
  const isLocalized = _.get(schema, 'pluginOptions.i18n.localized', false) === true;
  schema.attributes.locale = { writable: true, private: !isLocalized, configurable: false, visible: false, type: 'string' };
  schema.attributes.localizations = {
    type: 'relation', relation: 'oneToMany', target: uid, writable: false, private: !isLocalized, configurable: false, visible: false, unstable_virtual: true,
    joinColumn: { name: 'document_id', referencedColumn: 'document_id', referencedTable: identifiers.getTableName(schema.collectionName) },
  };
  for (const a of Object.values<any>(schema.attributes)) if (a.type === 'customField') a.type = 'string';
  return schema;
}

const builtins: any = {
  'admin::user': contentType({ collectionName: 'admin_users', info: { singularName: 'user', pluralName: 'users' }, attributes: {
    firstname: { type: 'string' }, lastname: { type: 'string' }, username: { type: 'string' }, email: { type: 'email', unique: true, private: true },
    password: { type: 'password', private: true }, resetPasswordToken: { type: 'string', private: true }, registrationToken: { type: 'string', private: true },
    isActive: { type: 'boolean', default: false, private: true }, blocked: { type: 'boolean', default: false, private: true }, preferedLanguage: { type: 'string' } } }, 'admin::user'),
  'plugin::upload.folder': contentType({ collectionName: 'upload_folders', info: { singularName: 'folder', pluralName: 'folders' }, attributes: {
    name: { type: 'string', required: true }, pathId: { type: 'integer', unique: true, required: true },
    parent: { type: 'relation', relation: 'manyToOne', target: 'plugin::upload.folder', inversedBy: 'children' },
    children: { type: 'relation', relation: 'oneToMany', target: 'plugin::upload.folder', mappedBy: 'parent' },
    files: { type: 'relation', relation: 'oneToMany', target: 'plugin::upload.file', mappedBy: 'folder' },
    path: { type: 'string', required: true } }, indexes: [
      { name: 'upload_folders_path_id_index', columns: ['path_id'], type: 'unique' }, { name: 'upload_folders_path_index', columns: ['path'], type: 'unique' } ] }, 'plugin::upload.folder', 'upload'),
  'plugin::upload.file': contentType({ collectionName: 'files', info: { singularName: 'file', pluralName: 'files' }, attributes: {
    name: { type: 'string', required: true }, alternativeText: { type: 'text' }, caption: { type: 'text' }, focalPoint: { type: 'json' }, width: { type: 'integer' }, height: { type: 'integer' },
    formats: { type: 'json' }, hash: { type: 'string', required: true }, ext: { type: 'string' }, mime: { type: 'string', required: true }, size: { type: 'decimal', required: true },
    url: { type: 'text', required: true }, previewUrl: { type: 'text' }, provider: { type: 'string', required: true }, provider_metadata: { type: 'json' },
    related: { type: 'relation', relation: 'morphToMany' },
    folder: { type: 'relation', relation: 'manyToOne', target: 'plugin::upload.folder', inversedBy: 'files', private: true },
    folderPath: { type: 'string', required: true, private: true } }, indexes: [
      { name: 'upload_files_folder_path_index', columns: ['folder_path'], type: null }, { name: 'upload_files_created_at_index', columns: ['created_at'], type: null },
      { name: 'upload_files_updated_at_index', columns: ['updated_at'], type: null }, { name: 'upload_files_name_index', columns: ['name'], type: null },
      { name: 'upload_files_size_index', columns: ['size'], type: null }, { name: 'upload_files_ext_index', columns: ['ext'], type: null } ] }, 'plugin::upload.file', 'upload'),
  'plugin::users-permissions.user': contentType({ collectionName: 'up_users', info: { singularName: 'user', pluralName: 'users' }, options: { timestamps: true }, attributes: {
    username: { type: 'string', unique: true, required: true }, email: { type: 'email', required: true }, provider: { type: 'string' }, password: { type: 'password', private: true, searchable: false },
    resetPasswordToken: { type: 'string', private: true, searchable: false }, confirmationToken: { type: 'string', private: true, searchable: false }, confirmed: { type: 'boolean', default: false },
    blocked: { type: 'boolean', default: false }, role: { type: 'relation', relation: 'manyToOne', target: 'plugin::users-permissions.role', inversedBy: 'users' } } }, 'plugin::users-permissions.user', 'users-permissions'),
  'plugin::users-permissions.role': contentType({ collectionName: 'up_roles', info: { singularName: 'role', pluralName: 'roles' }, attributes: {
    name: { type: 'string', required: true }, description: { type: 'string' }, type: { type: 'string', unique: true },
    permissions: { type: 'relation', relation: 'oneToMany', target: 'plugin::users-permissions.permission', mappedBy: 'role' },
    users: { type: 'relation', relation: 'oneToMany', target: 'plugin::users-permissions.user', mappedBy: 'role' } } }, 'plugin::users-permissions.role', 'users-permissions'),
  'plugin::users-permissions.permission': contentType({ collectionName: 'up_permissions', info: { singularName: 'permission', pluralName: 'permissions' }, attributes: {
    action: { type: 'string', required: true }, role: { type: 'relation', relation: 'manyToOne', target: 'plugin::users-permissions.role', inversedBy: 'permissions' } } }, 'plugin::users-permissions.permission', 'users-permissions'),
};

const schemas: any[] = Object.values(builtins);
for (const api of readdirSync(join(root, 'api'))) {
  for (const ct of readdirSync(join(root, 'api', api, 'content-types'))) {
    const json = JSON.parse(readFileSync(join(root, 'api', api, 'content-types', ct, 'schema.json'), 'utf8'));
    schemas.push(contentType(json, `api::${api}.${json.info.singularName}`));
  }
}
for (const category of readdirSync(join(root, 'components'))) {
  for (const file of readdirSync(join(root, 'components', category))) {
    const json = JSON.parse(readFileSync(join(root, 'components', category, file), 'utf8'));
    const uid = `${category}.${basename(file, '.json')}`;
    for (const a of Object.values<any>(json.attributes)) if (a.type === 'customField') a.type = 'string';
    schemas.push({ ...json, uid, category, modelType: 'component', modelName: basename(file, '.json'), globalId: _.upperFirst(_.camelCase(`component_${uid}`)) });
  }
}

const models = transformContentTypesToModels(schemas as any, identifiers);
models.push({ uid: 'strapi::core-store', singularName: 'strapi_core_store_settings', tableName: 'strapi_core_store_settings', attributes: {
  id: { type: 'increments' }, key: { type: 'string' }, value: { type: 'text' }, type: { type: 'string' }, environment: { type: 'string' }, tag: { type: 'string' } } } as any);
const metadata = createMetadata(models);
const schema = metadataToSchema(metadata);
// defaults may be functions: strip them for JSON
const out = JSON.parse(JSON.stringify(schema, (k, v) => (typeof v === 'function' ? '<fn>' : v)));
writeFileSync('/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-schema.json', JSON.stringify(out, null, 2));
// also the per-model column maps
const meta: any = {};
for (const [uid, m] of metadata) meta[uid] = { tableName: m.tableName, columnToAttribute: m.columnToAttribute };
writeFileSync('/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-metadata.json', JSON.stringify(meta, null, 2));
console.log('tables', schema.tables.length, 'models', metadata.size);
