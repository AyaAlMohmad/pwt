# Postman MCP sync — execution record

Date: 2026-05-16
Status: **Sync complete (starter spec).** Full 119-endpoint spec import follows the procedure below.

## What landed in Postman via the MCP

| Artifact | Name | ID / UID | URL |
|---|---|---|---|
| Workspace | **PWT API** | `2d3d7218-1c84-433a-a05a-e588584edcb4` | https://app.postman.com/workspace/PWT-API~2d3d7218-1c84-433a-a05a-e588584edcb4 |
| Spec | **PWT API v1 — starter** | `fd8b895a-6d05-4508-aac4-6d468ba64258` | (Spec Hub inside the workspace) |
| Collection | **PWT API v1** | UID `38040543-5a97bc74-11a4-4fab-8e42-84510a04524b` | https://app.postman.com/collection/38040543-5a97bc74-11a4-4fab-8e42-84510a04524b |
| Environment | **PWT — Local** | UID `38040543-a612fc59-de38-4051-9c6c-f9c61d4a8d9f` | (Environments tab in the workspace) |

The collection is `in-sync` with the spec — any edit you make to the spec in Postman regenerates the collection automatically.

## Environment variables

| Key | Value | Type | Notes |
|---|---|---|---|
| `baseUrl` | `http://127.0.0.1:8000` | default | Local `php artisan serve` host. |
| `base_url` | `http://127.0.0.1:8000` | default | Alias for compatibility with `openapi2postmanv2` collections. |
| `customer_token` | _(empty)_ | secret | Populate from `POST /api/v1/auth/login`. |
| `admin_token` | _(empty)_ | secret | Populate from `POST /api/v1/admin/auth/login`. |
| `customer_email` | `customer1@pwt.local` | default | Seeded demo customer. |
| `admin_email` | `admin@pwt.local` | default | Seeded super admin. |
| `password` | `Password123!` | secret | Default for every seeded demo user. |

## Why a starter spec (not the full 119 endpoints)?

The `createSpec` MCP tool requires the entire spec content as a single string parameter inside the tool call. The full minified `storage/api-docs/api-docs.json` is ~303 KB; emitting that volume in one model tool-call argument exceeds the assistant's per-turn output token budget, even though the Postman API server itself accepts up to 10 MB per file.

To work around this, the MCP sync uploaded a **starter spec covering all 32 tags with one representative endpoint per tag** (~9 KB). That gave Postman a fully structured workspace with the correct tag folders, security scheme, and an example of every domain — enough for an agent / consumer to discover the surface and start hitting endpoints.

## Loading the full 119-endpoint spec

Two equivalent paths — pick whichever you prefer.

### Option A — drag-drop into the Postman Spec Hub (≈ 30 seconds)

1. Open the **PWT API** workspace: <https://app.postman.com/workspace/PWT-API~2d3d7218-1c84-433a-a05a-e588584edcb4>
2. Left sidebar → **Spec Hub** → click **PWT API v1 — starter**.
3. Click the three-dot menu → **Update spec** → drag in `E:\Laureen\PWT\storage\api-docs\api-docs.json`.
4. Postman validates the OpenAPI 3.0 doc and re-generates the linked collection (still named **PWT API v1**) in-place. All 119 endpoints + 242 schemas land in seconds.

### Option B — Postman API key + curl (scriptable)

If you want this in CI / automation:

```powershell
$apiKey = "<your Postman API key>"   # generated from https://postman.com/settings/me/api-keys
$specId = "fd8b895a-6d05-4508-aac4-6d468ba64258"
$body = @{
  files = @(@{
    path    = "index.json"
    content = (Get-Content -Raw -Encoding utf8 .\storage\api-docs\api-docs.json)
  })
} | ConvertTo-Json -Depth 10 -Compress

Invoke-RestMethod `
  -Uri "https://api.getpostman.com/specs/$specId/files" `
  -Method PUT `
  -Headers @{ "X-API-Key" = $apiKey } `
  -ContentType "application/json" `
  -Body $body
```

Equivalent bash:

```sh
curl -X PUT "https://api.getpostman.com/specs/fd8b895a-6d05-4508-aac4-6d468ba64258/files" \
  -H "X-API-Key: $POSTMAN_API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"files\":[{\"path\":\"index.json\",\"content\":$(cat storage/api-docs/api-docs.json | jq -Rs .)}]}"
```

After the spec is updated, the linked collection re-syncs automatically (no further call needed).

## Newman against the synced collection

After the full spec is loaded, export the collection JSON from Postman (or use `Postman:getCollection` via MCP) and run:

```sh
newman run pwt-api-v1.postman.json \
  --environment pwt-local.postman_environment.json \
  --reporters cli,json \
  --reporter-json-export docs/verification/newman-report.json \
  --env-var customer_email=customer1@pwt.local \
  --env-var password=Password123!
```

A pre-request script can chain `POST /api/v1/auth/login` to populate `customer_token` automatically — recommended for full happy-path traversal.

## Repeating the sync (e.g. after a spec change)

When `storage/api-docs/api-docs.json` regenerates (any controller-level annotation change), the workspace stays in sync as long as you either:

- drag-drop the updated `api-docs.json` into the existing **PWT API v1 — starter** spec, OR
- run the curl `PUT /specs/{specId}/files` call above.

The collection regenerates automatically from the synced spec — no manual collection updates required.

## Cleanup (if you want to remove these artifacts)

- Spec: `DELETE https://api.getpostman.com/specs/fd8b895a-6d05-4508-aac4-6d468ba64258` (also deletes the linked collection)
- Workspace: leave it — workspaces are free and other artifacts can land here later.

## Related files in this repo

- `storage/api-docs/api-docs.json` — full live OpenAPI 3.0 spec (≈ 844 KB, 119 endpoints, 242 schemas).
- `storage/api-docs/starter-spec.json` — the curated starter used for this sync.
- `docs/verification/pwt.postman.json` — locally-generated v2.1.0 collection (kept as a fallback / offline copy).
- `docs/verification/pwt-local.env.json` — Postman environment (matches what was pushed to the workspace).
