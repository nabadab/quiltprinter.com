# QuiltPrinter Docker Deployment Plan

## Table of Contents
1. [Current Status](#current-status)
2. [Phase 1: Local MacOS MVP](#phase-1-local-macos-mvp)
3. [Phase 2: Production Readiness](#phase-2-production-readiness)
4. [Phase 3: AWS Deployment](#phase-3-aws-deployment)
5. [Monitoring & Maintenance](#monitoring--maintenance)

---

## Current Status

### ✅ Completed
- **Dockerfile**: PHP 8.2-Apache with required extensions (PDO, MySQL, GD)
- **docker-compose.yml**: Two-service setup (app + MySQL 8.0)
- **Database schema**: `databasesetup.sql` with print_queue, api_keys, and print_results tables
- **Environment variables**: Configuration via `.env` file and ENV vars
- **Volume mapping**: Code hot-reloading and persistent MySQL data
- **Database initialization**: Automatic schema setup on first run

### 🚧 Partially Complete
- **Environment configuration**: `.env.example` exists but needs `.env` file created
- **API authentication**: Database-backed but no initial API keys exist
- **Logging**: Directory created but no log rotation configured

### ❌ Not Started
- **Production Dockerfile**: Multi-stage build for smaller images
- **Health checks**: Liveness/readiness probes
- **AWS infrastructure**: ECR, ECS, RDS, ALB, secrets management
- **CI/CD pipeline**: Automated build and deployment
- **Monitoring**: CloudWatch logs and metrics
- **Backups**: Database backup strategy

---

## Phase 1: Local MacOS MVP

### Prerequisites
- Docker Desktop for Mac installed and running
- Git repository cloned
- Terminal access

### Step 1: Environment Setup

Create `.env` file for local development:

```bash
cp .env.example .env
```

The default values in `.env.example` are suitable for local development. Optionally customize:

```dotenv
# App database connection
DB_HOST=db
DB_NAME=quiltprinter
DB_USER=quiltuser
DB_PASS=quiltpass
DB_PORT=3306
DB_CHARSET=utf8mb4

# MySQL container
MYSQL_ROOT_PASSWORD=rootpass
```

### Step 2: Build and Start Services

```bash
# Build images and start containers
docker compose up --build

# Or run in detached mode
docker compose up --build -d
```

This will:
- Build the PHP application image
- Pull MySQL 8.0 image
- Create network bridge between services
- Initialize database with schema from `databasesetup.sql`
- Expose app on `http://localhost:8080`
- Expose MySQL on `localhost:3307` (for external tools)

### Step 3: Create Initial API Key

The application requires API keys for authentication. Add a test key:

```bash
# Connect to MySQL container
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter

# In MySQL shell, run:
INSERT INTO api_keys (api_key, name) VALUES 
    ('test_api_key_12345678', 'Local Development Key');
EXIT;
```

Or via one-liner:

```bash
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter \
  -e "INSERT INTO api_keys (api_key, name) VALUES ('test_api_key_12345678', 'Local Development Key');"
```

### Step 4: Verify Installation

Test the API endpoints:

```bash
# Test PNG API
curl -X POST http://localhost:8080/pngapi.php \
  -F "apikey=test_api_key_12345678" \
  -F "printer=test-printer-01" \
  -F "png=iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="

# Test queue endpoint (what printers poll)
curl http://localhost:8080/index.php?pid=test-printer-01

# Check database is accessible
docker compose exec db mysql -u quiltuser -pquiltpass quiltprinter \
  -e "SELECT COUNT(*) as job_count FROM print_queue;"
```

### Step 5: Monitor Logs

```bash
# Application logs (Apache + PHP)
docker compose logs -f app

# Database logs
docker compose logs -f db

# Application-specific logs
tail -f logs/pngapi.log
```

### Step 6: Stop and Cleanup

```bash
# Stop containers (preserves data)
docker compose stop

# Stop and remove containers (preserves volumes)
docker compose down

# Remove everything including volumes (CAUTION: deletes database)
docker compose down -v
```

### Common Issues & Solutions

#### Issue: Port 8080 already in use
```bash
# Change port in docker-compose.yml
ports:
  - "8081:80"  # Use port 8081 instead
```

#### Issue: Permission denied for logs directory
```bash
chmod -R 777 logs/
```

#### Issue: Database connection refused
```bash
# Wait for MySQL to fully initialize (~30 seconds on first run)
docker compose logs db | grep "ready for connections"
```

#### Issue: Code changes not reflected
```bash
# The volume is in delegated mode. Restart Apache:
docker compose exec app apache2ctl graceful
```

---

## Phase 2: Production Readiness

Before deploying to AWS, improve the Docker setup for production use.

### 2.1: Multi-Stage Production Dockerfile

Create `Dockerfile.prod`:

```dockerfile
# Build stage - not needed for PHP but useful for installing Composer deps
FROM php:8.2-apache AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Production stage
FROM base AS production

WORKDIR /var/www/html

# Copy application files (excluding dev files via .dockerignore)
COPY . /var/www/html

# Set permissions
RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Remove sensitive files that shouldn't be in production
RUN rm -f config_live.php \
    && rm -f .env .env.* \
    && rm -f testprint.php testprintstar.php

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost/index.php || exit 1

EXPOSE 80

# Use production PHP settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/expose_php = On/expose_php = Off/' "$PHP_INI_DIR/php.ini"

USER www-data
```

**Note**: The `USER www-data` at the end may cause issues if Apache needs to bind to port 80. Consider removing this line or running Apache on a high port (8080).

### 2.2: Production docker-compose.yml

Create `docker-compose.prod.yml`:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.prod
    ports:
      - "8080:80"
    environment:
      DB_HOST: ${DB_HOST}
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASS: ${DB_PASS}
      DB_PORT: ${DB_PORT:-3306}
      DB_CHARSET: ${DB_CHARSET:-utf8mb4}
    volumes:
      # Only mount logs in production, not code
      - ./logs:/var/www/html/logs
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/index.php"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
    restart: unless-stopped

  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASS}
    volumes:
      - db_data:/var/lib/mysql
      - ./databasesetup.sql:/docker-entrypoint-initdb.d/databasesetup.sql:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${MYSQL_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    restart: unless-stopped
    # Don't expose MySQL port in production (only internal access)

volumes:
  db_data:
```

### 2.3: Security Improvements

#### .dockerignore enhancements
Already good, but verify:
```
.git
.gitignore
.vscode
.env
.env.*
logs
*.log
config_live.php
node_modules
vendor
testprint.php
testprintstar.php
DEPLOYMENT_PLAN.md
README.md
```

#### Secrets Management
Never commit production credentials. Use:
- AWS Systems Manager Parameter Store
- AWS Secrets Manager
- Environment variables from ECS task definition

### 2.4: Logging Configuration

Add log rotation in production. Create `docker/logrotate.conf`:

```
/var/www/html/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data www-data
}
```

---

## Phase 3: AWS Deployment

### Architecture Overview

```
Internet
    ↓
[Route 53] → [CloudFront (optional)] → [ALB]
                                          ↓
                                    [Target Group]
                                          ↓
                                    [ECS Service]
                                          ↓
                                    [Fargate Tasks]
                                          ↓
                                    [RDS MySQL]
```

### Components
- **ECR**: Docker image registry
- **ECS Fargate**: Serverless container execution
- **RDS MySQL**: Managed database
- **ALB**: Application Load Balancer
- **Secrets Manager**: Database credentials
- **CloudWatch**: Logs and monitoring
- **VPC**: Network isolation

---

### 3.1: AWS Account Setup

#### Prerequisites
- AWS Account with billing enabled
- AWS CLI installed and configured
- IAM user with necessary permissions

```bash
# Install AWS CLI (Mac)
brew install awscli

# Configure credentials
aws configure
# Enter: Access Key ID, Secret Access Key, Region (e.g., us-east-1), Output format (json)
```

#### Create IAM policies (if needed)
Your IAM user needs permissions for:
- ECR (push/pull images)
- ECS (create clusters, services, tasks)
- RDS (create databases)
- VPC (create networks, security groups)
- ALB (create load balancers)
- Secrets Manager (create/read secrets)
- CloudWatch (write logs)

---

### 3.2: Setup ECR (Elastic Container Registry)

Create a private repository for the Docker image:

```bash
# Set variables
AWS_REGION="us-east-1"
ECR_REPO_NAME="quiltprinter"

# Create ECR repository
aws ecr create-repository \
    --repository-name $ECR_REPO_NAME \
    --region $AWS_REGION \
    --image-scanning-configuration scanOnPush=true

# Get repository URI (save this!)
ECR_REPO_URI=$(aws ecr describe-repositories \
    --repository-names $ECR_REPO_NAME \
    --region $AWS_REGION \
    --query 'repositories[0].repositoryUri' \
    --output text)

echo "ECR Repository URI: $ECR_REPO_URI"
```

---

### 3.3: Build and Push Docker Image

```bash
# Authenticate Docker to ECR
aws ecr get-login-password --region $AWS_REGION | \
    docker login --username AWS --password-stdin $ECR_REPO_URI

# Build production image
docker build -f Dockerfile.prod -t $ECR_REPO_NAME:latest .

# Tag for ECR
docker tag $ECR_REPO_NAME:latest $ECR_REPO_URI:latest
docker tag $ECR_REPO_NAME:latest $ECR_REPO_URI:v1.0.0

# Push to ECR
docker push $ECR_REPO_URI:latest
docker push $ECR_REPO_URI:v1.0.0
```

---

### 3.4: Setup VPC and Networking

#### Option A: Use Default VPC (quickest for MVP)

```bash
# Get default VPC ID
VPC_ID=$(aws ec2 describe-vpcs \
    --filters "Name=isDefault,Values=true" \
    --query 'Vpcs[0].VpcId' \
    --output text \
    --region $AWS_REGION)

# Get subnet IDs (need at least 2 in different AZs)
SUBNET_IDS=$(aws ec2 describe-subnets \
    --filters "Name=vpc-id,Values=$VPC_ID" \
    --query 'Subnets[*].SubnetId' \
    --output text \
    --region $AWS_REGION)

echo "VPC ID: $VPC_ID"
echo "Subnet IDs: $SUBNET_IDS"
```

#### Option B: Create Custom VPC (recommended for production)

Use AWS Console or CloudFormation template (see section 3.10).

---

### 3.5: Create RDS MySQL Database

```bash
# Create DB subnet group
aws rds create-db-subnet-group \
    --db-subnet-group-name quiltprinter-db-subnet \
    --db-subnet-group-description "Subnet group for QuiltPrinter RDS" \
    --subnet-ids $SUBNET_IDS \
    --region $AWS_REGION

# Create security group for RDS
RDS_SG_ID=$(aws ec2 create-security-group \
    --group-name quiltprinter-rds-sg \
    --description "Security group for QuiltPrinter RDS" \
    --vpc-id $VPC_ID \
    --region $AWS_REGION \
    --query 'GroupId' \
    --output text)

echo "RDS Security Group: $RDS_SG_ID"

# Create RDS MySQL instance
aws rds create-db-instance \
    --db-instance-identifier quiltprinter-db \
    --db-instance-class db.t3.micro \
    --engine mysql \
    --engine-version 8.0.35 \
    --master-username admin \
    --master-user-password "CHANGE_ME_TO_SECURE_PASSWORD" \
    --allocated-storage 20 \
    --vpc-security-group-ids $RDS_SG_ID \\n