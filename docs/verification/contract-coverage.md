# PWT API v1 - Contract Coverage Matrix

Generated on 2026-05-16 during Phase 23 final verification.

Source: `comments.xlsx` (sheet *API Review*) cross-referenced against `php artisan route:list --json`.

**Summary:** 27/40 fully matched (method + path), 3 path-only mismatch, 10 missing.

| # | Module | Method | Recommended Path | Priority | Implemented? |
|---|---|---|---|---|---|
| 1 | Authentication | POST | `/api/v1/auth/register` | High | YES |
| 2 | Authentication | POST | `/api/v1/auth/login` | High | YES |
| 3 | Authentication | POST | `/api/v1/auth/logout` | High | YES |
| 4 | Authentication | POST | `/api/v1/auth/forgot-password` | Medium | YES |
| 5 | Authentication | POST | `/api/v1/auth/reset-password` | Medium | YES |
| 6 | User | GET | `/api/v1/me` | High | YES |
| 7 | User | PUT/PATCH | `/api/v1/me` | Medium | PATH-ONLY |
| 8 | Catalog | GET | `/api/v1/categories` | Medium | YES |
| 9 | Catalog | GET | `/api/v1/products` | High | YES |
| 10 | Catalog | GET | `/api/v1/products/{id}` | High | YES |
| 11 | Cart / Order | POST | `/api/v1/orders` | High | YES |
| 12 | Cart / Order | GET | `/api/v1/orders` | High | YES |
| 13 | Cart / Order | GET | `/api/v1/orders/{id}` | High | YES |
| 14 | Cart / Order | POST/PATCH | `/api/v1/orders/{id}/cancel` | High | PATH-ONLY |
| 15 | Tracking | GET | `/api/v1/orders/{id}/tracking` | Medium | YES |
| 16 | Maintenance | POST | `/api/v1/maintenance-requests` | High | YES |
| 17 | Maintenance | GET | `/api/v1/maintenance-requests` | Medium | YES |
| 18 | Maintenance | GET | `/api/v1/maintenance-requests/{id}` | High | YES |
| 19 | Support | POST | `/api/v1/support-tickets` | Medium | YES |
| 20 | Payment | POST | `/api/v1/payments/initiate` | High | YES |
| 21 | Payment | POST | `/api/v1/payments/webhook` | Critical | YES |
| 22 | Payment | GET | `/api/v1/payments/{payment_id}/status` | High | YES |
| 23 | Admin | POST | `/api/v1/admin/auth/login` | Critical | YES |
| 24 | Admin | GET | `/api/v1/admin/dashboard` | Medium | YES |
| 25 | Admin | GET | `/api/v1/admin/orders` | High | YES |
| 26 | Admin | PATCH | `/api/v1/admin/orders/{id}/status` | High | YES |
| 27 | Admin | GET/POST/PUT/DELETE | `/api/v1/admin/products` | High | PATH-ONLY |
| 28 | Files | POST | `/api/v1/files` | Medium | YES |
| 29 | Notifications | GET | `/api/v1/notifications` | Low | YES |
| 30 | Notifications | PATCH | `/api/v1/notifications/{id}/read` | Low | YES |
| 31 | Product | ADMIN MUST BE ABLE TO EDIT PRODUCT DETAILS | `Missing Update Product API` |  | NO |
| 32 | Product | AVOID DELETING PRODUCTS, CONTROL VISIBILITY | `Missing activate/deactivate` |  | NO |
| 33 | Product | PRODUCTS NEED MEDIA MANAGEMENT | `Missing image upload/update` |  | NO |
| 34 | Category | ADMIN NEEDS TO MANAGE CATEGORIES | `Missing update category` |  | NO |
| 35 | User | USER LIFECYCLE MANAGEMENT | `Missing deactivate account` |  | NO |
| 36 | Order | ORDER FLOW CONTROL | `Missing status update` |  | NO |
| 37 | Order | USER/ADMIN CANCELLATION | `Missing cancel endpoint` |  | NO |
| 38 | Payment | PAYMENT CONFIRMATION FROM GATEWAY | `Missing webhook` |  | NO |
| 39 | Notification | USER NEEDS TO VIEW NOTIFICATIONS | `Missing notification list` |  | NO |
| 40 | Admin | TRACK ADMIN ACTIONS | `Missing audit logs` |  | NO |

## Notes

- `YES` = method + normalized path matches a registered Laravel route.
- `PATH-ONLY` = same path exists but under a different HTTP method (often a sheet typo or simplified verb).
- `NO` = the recommended path is not present in `route:list`.
- Path parameters in both sources are normalized to `{id}` for comparison.
