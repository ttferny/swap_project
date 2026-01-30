# Security Hardening Summary

## Recent Actions
- **Transport Security:** Added automatic HTTP→HTTPS redirects and HSTS headers via `enforce_https_transport()` and updated `apply_security_headers()` so every request uses TLS.
- **Sensitive Data at Rest:** Introduced AES-256-GCM helpers and now encrypt incident locations, descriptions, findings, and actions before persisting them; decrypt on read for admin/manager views.
- **JWT Enforcement:** Built JWT issuance/validation utilities (`issue_user_jwt()`, `validate_jwt_for_user()`, etc.), mint tokens on login, clear them on logout, and require valid tokens for every admin or dashboard page.
- **RBAC Guard:** Replaced per-page `require_login()` with `enforce_sensitive_route_guard()` on all administrative routes (admin hub, users, equipment certs, learning repo, insights, analytics) so only authenticated administrators with a fresh JWT can access them.
- **Access Logging:** Each protected endpoint now logs `sensitive_route_access` events through the guard, capturing user ID, path, and IP for investigation.
- **Least Privilege Defaults:** Analytics dashboard and other admin sections are limited strictly to the Administrator role, while student/staff experiences continue to rely on equipment-scoped helpers already in place.
