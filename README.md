# GLPI Development Environment

This repository contains a Docker Compose setup for GLPI with seed data for testing the alpreport plugin.

## Prerequisites

- Docker and Docker Compose installed
- Basic knowledge of command line

## Quick Start

### 1. Start the Environment

```bash
docker compose up -d
```

This will start:
- **GLPI** on http://localhost:8088
- **MySQL** database (internal)

### 2. Initial GLPI Setup

1. Open http://localhost:8088 in your browser
2. Follow the GLPI installation wizard:
   - Select language
   - Accept license terms
   - Database connection:
     - Host: `db`
     - Database: `glpi`
     - User: `glpi`
     - Password: `glpi`
3. Complete the installation and login with default credentials

## Seeding the Database

### Option A: Seed Fresh Installation

After completing the GLPI installation wizard:

```bash
docker compose exec -T db mysql -uglpi -pglpi glpi < glpi_seed_data.sql
```

### Option B: Start Completely Fresh

To start with a clean database and re-seed:

```bash
# Stop containers
docker compose down

# Remove existing database
rm -rf mysql/

# Start fresh
docker compose up -d

# Wait for GLPI to initialize (30-60 seconds)
sleep 60

# Complete GLPI installation wizard at http://localhost:8088

# Then seed the database
docker compose exec -T db mysql -uglpi -pglpi glpi < glpi_seed_data.sql
```

## Seed Data Overview

The seed data includes test assets for the **alpreport plugin** (supports Computer, NetworkEquipment, Rack):

### 4 Users (IDs: 200-203)
- **jdoe** (John Doe) - Graphic Designer
  - Phone: +1-555-0101, Mobile: +1-555-0201
  - Assigned to: WS-TEST-001
  
- **asmith** (Alice Smith) - Finance Manager
  - Phone: +1-555-0102, Mobile: +1-555-0202
  - Assigned to: PC-TEST-001
  
- **bwilliams** (Bob Williams)
  - Phone: +1-555-0103, Mobile: +1-555-0203
  - Assigned to: SW-TEST-001, SW-TEST-002 (network equipment)
  
- **itadmin** (IT Administrator) - System Administrator
  - Phone: +1-555-0999, Mobile: +1-555-0899
  - Tech support for ALL assets (computers, network equipment, racks)

### 3 Computers (IDs: 200-202)
- **WS-TEST-001** - Test Workstation (192.168.100.10)
  - **User:** John Doe (jdoe) - Graphic Designer
  - **Tech Support:** IT Administrator (itadmin)
  - OS: Windows 11 23H2 x86_64
  - CPU: Intel Core i7-11700 @ 2.5GHz (8 cores / 16 threads)
  - RAM: 32GB (2x 16GB DDR4-3200)
  - Storage: Samsung 970 EVO Plus 500GB NVMe SSD
  - GPU: NVIDIA GeForce GTX 1650 4GB
  - Network: Intel I219-LM Gigabit Ethernet
  
- **SRV-TEST-001** - Test Server (192.168.100.11)
  - **User:** None (shared server)
  - **Tech Support:** IT Administrator (itadmin)
  - OS: Ubuntu Server 22.04 LTS x86_64
  - CPU: Intel Xeon Silver 4214R @ 2.4GHz (12 cores / 24 threads)
  - RAM: 128GB (4x 32GB DDR4-3200)
  - Storage: 2x Samsung 860 EVO 2TB SSD (4TB total)
  - Network: Intel I350-T4 Quad Port Gigabit Ethernet
  
- **PC-TEST-001** - Test Desktop (192.168.100.12)
  - **User:** Alice Smith (asmith) - Finance Manager
  - **Tech Support:** IT Administrator (itadmin)
  - OS: Windows 10 22H2 x86_64
  - CPU: Intel Core i5-10400 @ 2.9GHz (6 cores / 12 threads)
  - RAM: 16GB (2x 8GB DDR4-2666)
  - Storage: WD Blue 1TB HDD
  - GPU: NVIDIA Quadro P620 2GB
  - Network: Intel I219-LM Gigabit Ethernet

### 3 Network Equipment (IDs: 200-202)
- **SW-TEST-001** - Core Switch (192.168.100.1)
  - **User:** Bob Williams (bwilliams)
  - **Tech Support:** IT Administrator (itadmin)
  
- **RTR-TEST-001** - Edge Router (192.168.100.254)
  - **User:** None (infrastructure device)
  - **Tech Support:** IT Administrator (itadmin)
  
- **SW-TEST-002** - Access Switch (192.168.100.2)
  - **User:** Bob Williams (bwilliams)
  - **Tech Support:** IT Administrator (itadmin)

### 2 Racks (IDs: 200-201)
- **RACK-TEST-001** - Server Rack (contains SRV-TEST-001)
  - **User:** None (shared infrastructure)
  - **Tech Support:** IT Administrator (itadmin)
  
- **RACK-TEST-002** - Network Rack (contains switches)
  - **User:** None (shared infrastructure)
  - **Tech Support:** IT Administrator (itadmin)

All items include:
- Serial numbers and inventory IDs
- Contact information
- Network ports and IP addresses
- Location data
- **User assignments** - users assigned to assets and tech support personnel
- Operating systems (computers only) with versions and architectures
- Hardware components (computers only):
  - Processors (CPU) with core/thread counts
  - Memory (RAM) with capacity and frequency
  - Storage drives (HDD/SSD) with capacity
  - Graphics cards (GPU) with memory
  - Network cards with bandwidth
- Proper GLPI relationships

## Verify Seed Data

Check that data was imported successfully:

```bash
docker compose exec db mysql -uglpi -pglpi glpi -e "
SELECT 'Computers' AS Type, COUNT(*) AS Count FROM glpi_computers WHERE id BETWEEN 200 AND 202
UNION ALL
SELECT 'Network Equipment', COUNT(*) FROM glpi_networkequipments WHERE id BETWEEN 200 AND 202
UNION ALL
SELECT 'Racks', COUNT(*) FROM glpi_racks WHERE id BETWEEN 200 AND 201;
"
```

Expected output:
```
Type              | Count
------------------|------
Computers         | 3
Network Equipment | 3
Racks             | 2
Operating Systems | 3
Users             | 4
```

## Common Commands

### View Logs

```bash
# GLPI logs
docker compose logs glpi

# Database logs
docker compose logs db

# Follow logs in real-time
docker compose logs -f
```

### Access MySQL CLI

```bash
docker compose exec db mysql -uglpi -pglpi glpi
```

### Restart Services

```bash
# Restart all services
docker compose restart

# Restart only GLPI
docker compose restart glpi

# Restart only database
docker compose restart db
```

### Stop Environment

```bash
# Stop containers (keeps data)
docker compose stop

# Stop and remove containers (keeps data)
docker compose down

# Stop, remove containers AND delete all data
docker compose down -v
rm -rf mysql/
```

## Plugin Setup

The **reporteur/alpreport** plugin is mounted at `./plugins/reporteur/`.

To activate the plugin in GLPI:
1. Login to GLPI (http://localhost:8088)
2. Go to **Setup > Plugins**
3. Find "Alp Report" and click **Install**
4. Then click **Enable**

## File Structure

```
.
├── docker-compose.yml       # Docker Compose configuration
├── .env                     # Environment variables (DB credentials)
├── mysql/                   # MySQL data directory (created on first run)
├── plugins/                 # GLPI plugins directory
│   └── reporteur/          # Alpreport plugin
├── glpi_seed_data.sql      # Seed data for testing
└── README.md               # This file
```

## Troubleshooting

### Database Connection Refused

If you see "Connection refused" errors:

```bash
# Check if database is ready
docker compose exec db mysqladmin -uglpi -pglpi ping

# Wait for database to be ready (might take 30-60 seconds on first start)
```

### Permission Issues with Mounted Volumes

If you see permission errors in logs:

```bash
# Check current ownership
ls -la plugins/

# The :U flag in docker-compose.yml should handle this automatically with Podman
# For Docker, you may need to adjust ownership manually
```

### Reset Everything

To completely reset the environment:

```bash
docker compose down -v
rm -rf mysql/ plugins/reporteur/.git/index.lock
docker compose up -d
```

### Import Errors

If seed data import fails:

```bash
# Check for detailed errors
docker compose exec -T db mysql -uglpi -pglpi glpi < glpi_seed_data.sql

# Verify database is accessible
docker compose exec db mysql -uglpi -pglpi -e "SHOW TABLES;" glpi | wc -l
```

## Database Credentials

Default credentials (configured in `.env`):

- **Database Host:** db
- **Database Name:** glpi
- **Username:** glpi
- **Password:** glpi
- **Root Password:** Random (generated on first start)

## Notes

- All seed data uses IDs starting from 200 to avoid conflicts with existing GLPI data
- The MySQL data directory is persisted in `./mysql/` 
- Plugins are mounted from `./plugins/` with automatic ownership mapping (`:U` flag)
- The seed file uses `INSERT IGNORE` so it can be run multiple times safely

## Support

For issues with:
- **GLPI:** Check official documentation at https://glpi-project.org/
- **Alpreport Plugin:** Check plugin-specific documentation
- **Docker/Compose:** Check Docker documentation

## License

This setup is provided as-is for development and testing purposes.
