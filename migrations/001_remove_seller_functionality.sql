-- ============================================================================
-- Migration: Remove Seller Functionality
-- Date: 2025-10-28
-- Description: Remove all seller-related tables and simplify schema for
--              single distributor model
-- ============================================================================

-- BACKUP REMINDER:
-- Before running this migration, make sure you have backed up your database!
-- Command: mysqldump -u username -p database_name > backup.sql

-- ============================================================================
-- PART 1: DROP SELLER-SPECIFIC TABLES
-- ============================================================================

-- Drop seller tables (7 tables)
DROP TABLE IF EXISTS `seller_withdrawals`;
DROP TABLE IF EXISTS `seller_payment_settings`;
DROP TABLE IF EXISTS `seller_package_orders`;
DROP TABLE IF EXISTS `seller_packages`;
DROP TABLE IF EXISTS `seller_language_settings`;
DROP TABLE IF EXISTS `seller_bank_accounts`;
DROP TABLE IF EXISTS `seller_applications`;

-- ============================================================================
-- PART 2: UPDATE USERS TABLE - Simplify user_type
-- ============================================================================

-- First, let's see what seller users we have (for reference)
-- SELECT id, name, email, user_type FROM users WHERE user_type = 'seller';

-- Option 1: Convert all sellers to customers
-- UPDATE users SET user_type = 'customer' WHERE user_type = 'seller';

-- Option 2: Delete seller accounts (CAREFUL!)
-- DELETE FROM users WHERE user_type = 'seller';

-- Update user_type ENUM to only allow 'customer' and 'admin'
-- Note: This requires recreating the column
ALTER TABLE `users`
MODIFY COLUMN `user_type` ENUM('customer', 'admin') DEFAULT 'customer';

-- Remove seller-related fields from users table (if desired)
-- ALTER TABLE `users` DROP COLUMN IF EXISTS `balance`;

-- ============================================================================
-- PART 3: UPDATE PRODUCTS TABLE - Remove seller_id
-- ============================================================================

-- Option 1: Set all products to admin (user_id = 1)
-- UPDATE products SET user_id = 1 WHERE user_id IS NOT NULL;

-- Option 2: Make user_id nullable (keep history but not required)
ALTER TABLE `products`
MODIFY COLUMN `user_id` INT(11) DEFAULT NULL COMMENT 'Legacy field - no longer used';

-- Set all existing products as approved and published (since admin manages all)
UPDATE `products`
SET `approved` = 1, `published` = 1
WHERE `approved` = 0 OR `published` = 0;

-- ============================================================================
-- PART 4: UPDATE ORDERS TABLE - Remove seller_id
-- ============================================================================

-- Remove seller_id from orders table
ALTER TABLE `orders`
DROP COLUMN IF EXISTS `seller_id`;

-- Remove seller_id from order_details table
ALTER TABLE `order_details`
DROP COLUMN IF EXISTS `seller_id`;

-- ============================================================================
-- PART 5: UPDATE/DROP COMMISSION TABLES
-- ============================================================================

-- Option 1: Drop commission_histories table entirely (if not needed)
DROP TABLE IF EXISTS `commission_histories`;

-- Option 2: If you want to keep for historical data, just remove seller fields
-- ALTER TABLE `commission_histories` DROP COLUMN IF EXISTS `seller_id`;
-- ALTER TABLE `commission_histories` DROP COLUMN IF EXISTS `seller_earning`;

-- ============================================================================
-- PART 6: CLEANUP OTHER REFERENCES
-- ============================================================================

-- Clean up any staff roles related to sellers (if exists)
-- DELETE FROM model_has_roles WHERE role_id IN (
--     SELECT id FROM roles WHERE name LIKE '%seller%'
-- );

-- Clean up seller-related permissions (if exists)
-- DELETE FROM permissions WHERE name LIKE '%seller%';

-- Remove seller-related business settings (if exists)
DELETE FROM `business_settings` WHERE `type` LIKE '%seller%';

-- ============================================================================
-- PART 7: VERIFY CHANGES
-- ============================================================================

-- Check remaining tables
-- SHOW TABLES LIKE '%seller%';

-- Check user types
-- SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type;

-- Check products without user_id
-- SELECT COUNT(*) as products_count FROM products;

-- Check orders structure
-- DESCRIBE orders;

-- ============================================================================
-- ROLLBACK PLAN (if needed)
-- ============================================================================

-- If you need to rollback, restore from your backup:
-- mysql -u username -p database_name < backup.sql

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
