# Copilot Instructions

## Project overview
- PHP print-queue backend for Epson TM-T88VII.
- Entry points: `index.php`, `textapi.php`, `xmlapi.php`, `pngapi.php`, `starpngapi.php`.
- Database access via `dbconnection.php` (PDO, MySQL).

## Conventions
- Keep public APIs stable.
- Favor small, targeted changes.
- Avoid reformatting unrelated code.
- When adding new endpoints, follow the JSON response patterns in `pngapi.php`.

## Environment
- Prefer env vars (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`, `DB_CHARSET`) over local config.
- Logs go to `/logs`.
