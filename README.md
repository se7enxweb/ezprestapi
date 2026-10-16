# ezprestapi

The REST content API of Exponential, versions 1 and 2 in one extension: list, read, create and remove content
remotely over HTTP with an OAuth2 token or a personal API key.

- One activation: `ActiveExtensions[]=ezprestapi`.
- One provider (`ezp7xRestApiProvider`) and one route table for the prefixes `ezp` and `ezpl`.
- The reads answer at `/api/ezp/v1/...` and `/api/ezp/v2/...` with the same bodies the v1 provider
  (ezprestapiprovider) gave; the writes (create, update, delete) answer at `/api/ezp/v2/...`.
- Authentication is the kernel's: `POST /api/auth/v1/oauth/token`, the same token for v1 and v2.
- Every read and write checks the current user's policies the way the content module does
  (Exponential 6.0.15 or later).

## Routes

| Route | Versions |
|---|---|
| `GET /content/node/:nodeId` | v1, v2 |
| `GET /content/node/:nodeId/list[/offset/:n][/limit/:n][/sort/:key[/asc\|desc]]` | v1, v2 |
| `GET /content/node/:nodeId/fields`, `/field/:fieldIdentifier`, `/childrenCount`, `/listAtom` | v1, v2 |
| `GET /content/object/:objectId`, `/fields`, `/field/:fieldIdentifier` | v1, v2 |
| `POST /content/node/create` | v2 |
| `POST /content/node/:nodeId` (update, 501 for now) | v2 |
| `POST` or `DELETE /content/node/delete/:nodeId` | v2 |

Example: `GET /api/ezp/v1/content/node/2/list/offset/0/limit/20?ResponseGroups=Fields`.

## Replacing ezprestapiprovider

ezprestapiprovider, the original v1 content provider, is deprecated: since its 6.0.4 it is a compatibility
shim whose `ezpRestApiProvider` and `ezpRestContentController` extend the classes of this extension and whose
rest.ini maps the same prefixes to `ezp7xRestApiProvider`. To migrate:

1. Activate `ezprestapi` (and keep it before or after the provider; the order no longer matters).
2. Remove `ActiveExtensions[]=ezprestapiprovider`, clear the INI cache and regenerate the extension autoloads.

Nothing else changes: the v1 URLs, their response bodies, the OAuth clients and tokens stay as they were.

The create, update and delete calls are backed by nxc_powercontent.
