#!/usr/bin/env node
/**
 * Generates a Postman collection from the OpenAPI spec (issue #13).
 *
 *   npm run generate:postman
 *
 * Outputs:
 *   static/postman/voxra-api.postman_collection.json
 *   static/postman/voxra-api.postman_environment.json  ({{host}}, {{bearer_token}})
 *
 * CI regenerates and fails on drift, so the committed collection always
 * matches static/openapi/openapi.yaml.
 */
const fs = require('fs');
const path = require('path');
const converter = require('openapi-to-postmanv2');

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function stripIds(node) {
  if (Array.isArray(node)) {
    node.forEach(stripIds);
    return;
  }
  if (node && typeof node === 'object') {
    if (typeof node.id === 'string' && UUID_RE.test(node.id)) {
      delete node.id;
    }
    Object.values(node).forEach(stripIds);
  }
}

const root = path.resolve(__dirname, '..');
const specPath = path.join(root, 'static', 'openapi', 'openapi.yaml');
const outDir = path.join(root, 'static', 'postman');
const collectionPath = path.join(outDir, 'voxra-api.postman_collection.json');
const environmentPath = path.join(outDir, 'voxra-api.postman_environment.json');

const spec = fs.readFileSync(specPath, 'utf8');

converter.convert(
  { type: 'string', data: spec },
  {
    folderStrategy: 'Tags',
    // 'Schema' emits stable <type> placeholders; 'Example' fakes random
    // enum/int values on every run, which breaks the CI drift check
    requestParametersResolution: 'Schema',
    exampleParametersResolution: 'Schema',
    includeAuthInfoInExample: false,
  },
  (err, result) => {
    if (err) {
      console.error('Conversion error:', err);
      process.exit(1);
    }
    if (!result.result) {
      console.error('Conversion failed:', result.reason);
      process.exit(1);
    }

    const collection = result.output[0].data;

    // drop the random per-item UUIDs the converter generates, so
    // regeneration is diff-stable; Postman re-assigns ids on import
    stripIds(collection);
    collection.info._postman_id = 'voxra-api-v1';
    collection.info.name = 'Voxra API (v1)';

    // collection-level bearer auth on {{bearer_token}}
    collection.auth = {
      type: 'bearer',
      bearer: [{ key: 'token', value: '{{bearer_token}}', type: 'string' }],
    };

    collection.variable = [
      { key: 'host', value: 'app.voxra.uk' },
      { key: 'baseUrl', value: 'https://{{host}}' },
    ];

    const environment = {
      id: 'voxra-api-env',
      name: 'Voxra API environment',
      values: [
        { key: 'host', value: 'app.voxra.uk', enabled: true },
        { key: 'baseUrl', value: 'https://{{host}}', enabled: true },
        { key: 'bearer_token', value: '', type: 'secret', enabled: true },
        { key: 'domain_uuid', value: '', enabled: true },
      ],
      _postman_variable_scope: 'environment',
    };

    fs.mkdirSync(outDir, { recursive: true });
    fs.writeFileSync(collectionPath, JSON.stringify(collection, null, 2) + '\n');
    fs.writeFileSync(environmentPath, JSON.stringify(environment, null, 2) + '\n');

    console.log(`Wrote ${path.relative(root, collectionPath)} (${collection.item.length} folders)`);
    console.log(`Wrote ${path.relative(root, environmentPath)}`);
  }
);
