#!/bin/bash

# ============================================================================
# Database Migration Script
# Description: Safely apply database migrations with backup
# ============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
DB_HOST="localhost"
DB_PORT="3306"
BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo -e "${GREEN}==================================================================${NC}"
echo -e "${GREEN}          Database Migration Tool - Phase 4${NC}"
echo -e "${GREEN}==================================================================${NC}"
echo ""

# Check if migration file is provided
if [ -z "$1" ]; then
    echo -e "${RED}Error: No migration file specified${NC}"
    echo "Usage: ./apply_migration.sh <migration_file>"
    echo "Example: ./apply_migration.sh 002_phase4_database_optimization.sql"
    exit 1
fi

MIGRATION_FILE=$1

# Check if migration file exists
if [ ! -f "$MIGRATION_FILE" ]; then
    echo -e "${RED}Error: Migration file not found: $MIGRATION_FILE${NC}"
    exit 1
fi

# Get database credentials
echo -e "${YELLOW}Enter database credentials:${NC}"
read -p "Database name: " DB_NAME
read -p "Database user: " DB_USER
read -sp "Database password: " DB_PASS
echo ""

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Step 1: Create backup
echo ""
echo -e "${YELLOW}Step 1: Creating database backup...${NC}"
BACKUP_FILE="${BACKUP_DIR}/backup_${DB_NAME}_${TIMESTAMP}.sql"

mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Backup created successfully: $BACKUP_FILE${NC}"
    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo -e "  Backup size: $BACKUP_SIZE"
else
    echo -e "${RED}✗ Failed to create backup${NC}"
    exit 1
fi

# Step 2: Show migration preview
echo ""
echo -e "${YELLOW}Step 2: Migration Preview${NC}"
echo -e "Migration file: $MIGRATION_FILE"
echo -e "Database: $DB_NAME"
echo -e "Backup: $BACKUP_FILE"
echo ""

# Step 3: Confirm execution
read -p "Do you want to proceed with the migration? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    echo -e "${YELLOW}Migration cancelled.${NC}"
    exit 0
fi

# Step 4: Apply migration
echo ""
echo -e "${YELLOW}Step 3: Applying migration...${NC}"

mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIGRATION_FILE" 2>/dev/null

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Migration applied successfully!${NC}"
else
    echo -e "${RED}✗ Migration failed!${NC}"
    echo -e "${YELLOW}To rollback, run:${NC}"
    echo -e "mysql -u $DB_USER -p $DB_NAME < $BACKUP_FILE"
    exit 1
fi

# Step 5: Verify changes
echo ""
echo -e "${YELLOW}Step 4: Verifying changes...${NC}"

# Check for seller tables
SELLER_TABLES=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES LIKE '%seller%'" 2>/dev/null | wc -l)

if [ $SELLER_TABLES -eq 0 ]; then
    echo -e "${GREEN}✓ All seller tables removed${NC}"
else
    echo -e "${YELLOW}⚠ Warning: $SELLER_TABLES seller-related tables still exist${NC}"
fi

# Check user types
echo ""
echo -e "${YELLOW}User types distribution:${NC}"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type" 2>/dev/null

# Step 6: Success summary
echo ""
echo -e "${GREEN}==================================================================${NC}"
echo -e "${GREEN}          Migration Completed Successfully!${NC}"
echo -e "${GREEN}==================================================================${NC}"
echo ""
echo -e "Backup location: ${GREEN}$BACKUP_FILE${NC}"
echo -e "Keep this backup for at least 30 days."
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Test your application thoroughly"
echo "2. Monitor database performance"
echo "3. If issues occur, rollback using: mysql -u $DB_USER -p $DB_NAME < $BACKUP_FILE"
echo ""
echo -e "${GREEN}Done!${NC}"
