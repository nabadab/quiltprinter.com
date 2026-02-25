# QuiltPrinter PHP API

## Local development (Docker)
1. Copy `.env.example` to `.env` and adjust values if needed.
2. Start containers:
   - `docker compose up --build`
3. Open http://localhost:8080

## Database
- Schema is initialized from `databasesetup.sql` on first run.
- MySQL is exposed on `localhost:3307`.

## Notes
- Logs are written to `./logs`.
- API authentication uses the `api_keys` table.
