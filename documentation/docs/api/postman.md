---
id: postman
title: Postman collection
description: One-click import to explore the Voxra API from Postman or Insomnia.
---

# Postman collection

A ready-made collection generated from the [OpenAPI spec](/openapi/openapi.yaml),
covering every endpoint including the CDR API, token management and webhooks.

- **Collection:** [voxra-api.postman_collection.json](/postman/voxra-api.postman_collection.json)
- **Environment template:** [voxra-api.postman_environment.json](/postman/voxra-api.postman_environment.json)

## Import

1. In Postman: **Import** → drop in both files (Insomnia imports the
   collection file directly).
2. Select the *Voxra API environment* and fill in:
   - `host` — your portal host (default `app.voxra.uk`)
   - `bearer_token` — an API token (Settings → API Tokens, or ask your admin)
   - `domain_uuid` — your account's domain UUID, used in tenant-scoped paths
3. Authentication is set at the collection level (`Bearer {{bearer_token}}`),
   so every request works once the environment is filled in.

## Keeping it fresh

The collection is regenerated from `static/openapi/openapi.yaml` by
`npm run generate:postman` (in `documentation/`); CI fails any pull request
where the committed collection has drifted from the spec.
