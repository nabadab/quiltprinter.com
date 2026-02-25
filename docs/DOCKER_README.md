# Docker Quickstart — QuiltPrinter

This guide helps engineers who haven't used Docker before to install Docker, configure this repository, and run the QuiltPrinter app locally on Windows, macOS, or Linux.

Target audience: engineers familiar with the terminal and basic Git, but new to Docker.

Prerequisites
- A machine with internet access
- Git (to clone repository)
- Local terminal (PowerShell or WSL on Windows; Terminal on Mac; Bash on Linux)

Overview
1. Install Docker for your OS
2. Clone the repo and create the `.env` file
3. Build and run containers with `docker compose`
4. Verify the app and database are running

IMPORTANT: This guide runs the stack for local development only. Do not use the provided default secrets in production.

1) Install Docker

Windows (Docker Desktop)
- Recommended: Docker Desktop for Windows. Requires Windows 10/11 Pro, Enterprise, or Windows Subsystem for Linux (WSL2) enabled for Home.
- Download & install: https://www.docker.com/get-started
- After install: ensure Docker Desktop is running and WSL2 integration enabled (if on Home). Use PowerShell as admin to enable WSL2 if needed.

macOS (Docker Desktop)
- Download & install: https://www.docker.com/get-started (Apple Silicon and Intel builds available)
- After install: open Docker Desktop and allow required permissions.

Linux (Docker Engine + Compose plugin)
- Ubuntu / Debian example:

```bash
# Install prerequisites
sudo apt update
sudo apt install -y ca-certificates curl gnupg lsb-release

# Add Docker's official GPG key
sudo mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Add your user to docker group (log out/in afterwards)
sudo groupadd docker || true
sudo usermod -aG docker $USER
```

Verify Docker is installed and daemon is running:

```bash
docker --version
docker compose version
docker info
```

If `docker` or `docker compose` is not found, restart your terminal or Docker Desktop.

2) Clone repository and prepare environment

```bash
git clone <repo-url>
cd quiltprinter.com

# Create local env file from example
cp .env.example .env
```

Open `.env` and confirm values. Defaults are suitable for local development:

```text
DB_HOST=db
DB_NAME=quiltprinter
DB_USER=quiltuser
DB_PASS=quiltpass
DB_PORT=3306
MYSQL_ROOT_PASSWORD=rootpass
```

3) Build and run the containers

From the repository root run:

```bash
# Build and start (foreground)
docker compose up --build

# Or detached
docker compose up --build -d
```

What happens:
- PHP + Apache container is built from `Dockerfile`
- MySQL 8.0 container is pulled and initialized
- `databasesetup.sql` runs on first startup to create required tables
- App is reachable on http://localhost:8080

4) Verify services are running

Check container status:

```bash
docker compose ps
```

Check MySQL initialization completed:

```bash
docker compose logs db | tail -n 30
# Look for "ready for connections"
```

Confirm DB tables exist:

```bash
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter -e "SHOW TABLES;"
```

Queue a test print job (PNG API):

```bash
curl -X POST http://localhost:8080/pngapi.php \
  -F "apikey=test_api_key_12345678" \
  -F "printer=test-printer-01" \
  -F "png=iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
```

You should receive a JSON success response like `{"success":true,"message":"Print job queued"...}`.

If you get an authentication error, create the test API key in the database:

```bash
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter -e "INSERT INTO api_keys (api_key, name) VALUES ('test_api_key_12345678','Local Development Key');"
```

5) Common developer commands

```bash
# Tail application logs
docker compose logs -f app

# Tail database logs
docker compose logs -f db

# Run a one-off mysql client inside DB container
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter

# Restart services
docker compose restart

# Stop and remove containers (preserve volume)
docker compose down

# Remove containers, networks, AND volumes (destroys DB data)
docker compose down -v
```

6) Troubleshooting

- Port conflict on 8080: change host port in `docker-compose.yml` under `app.ports` (e.g. `"8081:80"`).
- Permission problems writing `logs/`: run `chmod -R 777 logs/` locally to debug, then fix ownership in your environment.
- DB not ready: wait ~20–30s on first run; re-check `docker compose logs db` for progress.
- If code changes aren't visible: `docker compose exec app apache2ctl graceful` to reload Apache.

7) Windows-specific tips

- If running Docker Desktop on Windows Home, enable WSL2 and install a supported Linux distribution (Ubuntu) and enable integration in Docker Desktop Settings → Resources → WSL Integration.
- Running `curl` in PowerShell: use double quotes and escape where necessary, or use WSL bash shell for consistency.

8) Next steps

- Once local testing is successful, follow `docs/DEPLOYMENT_PLAN.md` to prepare production-ready images and the AWS deployment steps.
- Consider adding a local `README.local.md` with shortcuts or helper scripts for common dev tasks.

If you'd like, I can also add a small helper script `scripts/local-up.sh` which runs the common commands and health checks.
