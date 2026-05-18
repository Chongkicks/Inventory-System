<?php

// Basahin ang .env values nang isang beses at gumamit ng default kapag kulang ang setting.
function env_value($key, $default = null)
{
    static $values = null;

    if ($values === null) {
        $values = [];
        $envFile = __DIR__ . '/../.env';

        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $line, 2));
                $values[$name] = trim($value, "\"'");
            }
        }
    }

    return $values[$key] ?? $default;
}

// Database connection settings; defaults are tuned for the local XAMPP setup.
$dbHost = env_value('db_host', '127.0.0.1');
$dbPort = env_value('db_port', 3307);
$dbUser = env_value('db_user', 'root');
$dbPass = env_value('db_pass', '');
$dbName = env_value('db_name', 'inventory_db');

$conn = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error . ' (host=' . $dbHost . ' port=' . $dbPort . ')');
}

// Create/select the app database automatically so first run setup is simple.
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName`");
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

// Base tables. Later migration helpers below keep older databases compatible.
$conn->query("
    CREATE TABLE IF NOT EXISTS products (
        productID INT AUTO_INCREMENT PRIMARY KEY,
        productName VARCHAR(255) NOT NULL,
        category VARCHAR(120) NOT NULL DEFAULT '',
        quantity INT NOT NULL DEFAULT 0,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        dateCreated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        dateDeleted DATE DEFAULT NULL
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS product_sizes (
        productSizeID INT AUTO_INCREMENT PRIMARY KEY,
        productID INT NOT NULL,
        sizeLabel VARCHAR(10) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        dateCreated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY product_size_unique (productID, sizeLabel),
        INDEX (productID)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS transactions (
        transactionID INT AUTO_INCREMENT PRIMARY KEY,
        productID INT NULL,
        productName VARCHAR(255) NOT NULL,
        type VARCHAR(30) NOT NULL,
        quantityChange INT NOT NULL DEFAULT 0,
        quantityAfter INT NULL,
        remarks VARCHAR(255) NULL,
        dateCreated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        dateDeleted DATE DEFAULT NULL,
        INDEX (productID),
        INDEX (dateCreated)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        userID INT AUTO_INCREMENT PRIMARY KEY,
        fullName VARCHAR(80) NOT NULL,
        username VARCHAR(80) NOT NULL UNIQUE,
        email VARCHAR(80) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
        dateCreated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        dateDeleted DATE DEFAULT NULL
    )
");

// Check if a table column exists before running ALTER statements.
function column_exists($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    return $result && $result->num_rows > 0;
}

// Rename legacy column names only when the old column exists and the new one does not.
function rename_column($conn, $table, $oldColumn, $newColumn, $definition)
{
    if (column_exists($conn, $table, $oldColumn) && !column_exists($conn, $table, $newColumn)) {
        $conn->query("ALTER TABLE `$table` CHANGE `$oldColumn` `$newColumn` $definition");
    }
}

// Add a missing column, ignoring duplicate-column errors from concurrent page loads.
function ensure_column($conn, $table, $column, $definition)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($result && $result->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }
}

// Remove obsolete columns left by older schema versions.
function drop_column_if_exists($conn, $table, $column)
{
    if (column_exists($conn, $table, $column)) {
        $conn->query("ALTER TABLE `$table` DROP COLUMN `$column`");
    }
}

// Check indexes before creating unique keys.
function index_exists($conn, $table, $index)
{
    $table = $conn->real_escape_string($table);
    $index = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");

    return $result && $result->num_rows > 0;
}

// Return MySQL metadata for a column, including auto_increment status.
function column_details($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    return $result ? $result->fetch_assoc() : null;
}

// Read the ordered list of primary key columns for one table.
function primary_key_columns($conn, $table)
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'");
    $keyParts = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $keyParts[(int) $row['Seq_in_index']] = $row['Column_name'];
        }
    }

    ksort($keyParts);

    return array_values($keyParts);
}

// Detect broken ids before converting a column back to AUTO_INCREMENT.
function table_ids_need_repair($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("
        SELECT
            COUNT(*) AS totalRows,
            COUNT(DISTINCT `$column`) AS distinctIds,
            COALESCE(SUM(CASE WHEN `$column` IS NULL OR `$column` <= 0 THEN 1 ELSE 0 END), 0) AS invalidIds
        FROM `$table`
    ");

    if (!$result) {
        return false;
    }

    $row = $result->fetch_assoc();
    $totalRows = (int) ($row['totalRows'] ?? 0);
    $distinctIds = (int) ($row['distinctIds'] ?? 0);
    $invalidIds = (int) ($row['invalidIds'] ?? 0);

    return $invalidIds > 0 || $distinctIds < $totalRows;
}

// Repair ids, primary keys, and AUTO_INCREMENT flags for upgraded databases.
function ensure_auto_increment_primary_key($conn, $table, $column)
{
    if (!column_exists($conn, $table, $column)) {
        return;
    }

    $tableName = $conn->real_escape_string($table);
    $columnName = $conn->real_escape_string($column);
    $column = (string) $column;
    $primaryColumns = primary_key_columns($conn, $table);
    $hasPrimaryId = count($primaryColumns) === 1 && $primaryColumns[0] === $column;
    $details = column_details($conn, $table, $column);
    $isAutoIncrement = str_contains(strtolower((string) ($details['Extra'] ?? '')), 'auto_increment');

    if (!table_ids_need_repair($conn, $table, $column) && $hasPrimaryId && $isAutoIncrement) {
        return;
    }

    if (table_ids_need_repair($conn, $table, $column)) {
        $repairColumn = '__repair_' . $columnName;

        if ($isAutoIncrement) {
            $conn->query("ALTER TABLE `$tableName` MODIFY `$columnName` INT NOT NULL");
            $isAutoIncrement = false;
        }

        if (column_exists($conn, $table, $repairColumn)) {
            $conn->query("ALTER TABLE `$tableName` DROP COLUMN `$repairColumn`");
        }

        $conn->query("ALTER TABLE `$tableName` ADD COLUMN `$repairColumn` INT NOT NULL AUTO_INCREMENT UNIQUE FIRST");
        $conn->query("UPDATE `$tableName` SET `$columnName` = `$repairColumn`");
        $conn->query("ALTER TABLE `$tableName` DROP COLUMN `$repairColumn`");
    }

    $primaryColumns = primary_key_columns($conn, $table);
    $hasPrimaryId = count($primaryColumns) === 1 && $primaryColumns[0] === $column;

    if (!empty($primaryColumns) && !$hasPrimaryId) {
        $conn->query("ALTER TABLE `$tableName` DROP PRIMARY KEY");
        $hasPrimaryId = false;
    }

    if ($hasPrimaryId) {
        $conn->query("ALTER TABLE `$tableName` MODIFY `$columnName` INT NOT NULL AUTO_INCREMENT");
        return;
    }

    $conn->query("ALTER TABLE `$tableName` MODIFY `$columnName` INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
}

// Older imports sometimes stored size rows with productID 0; reconnect them when safe.
function repair_zero_product_size_links($conn)
{
    if (!column_exists($conn, 'product_sizes', 'productID') || !column_exists($conn, 'products', 'productID')) {
        return;
    }

    $zeroSizeRows = $conn->query("SELECT COUNT(*) AS total FROM product_sizes WHERE productID <= 0");
    $zeroSizeCount = $zeroSizeRows ? (int) $zeroSizeRows->fetch_assoc()['total'] : 0;

    if ($zeroSizeCount === 0) {
        return;
    }

    $activeUniforms = $conn->query("
        SELECT productID
        FROM products
        WHERE dateDeleted IS NULL
        AND category IN ('PE Uniform', 'Uniform')
        ORDER BY productID ASC
    ");

    if ($activeUniforms && $activeUniforms->num_rows === 1) {
        $productId = (int) $activeUniforms->fetch_assoc()['productID'];
        $conn->query("UPDATE product_sizes SET productID = $productId WHERE productID <= 0");
    }
}

// Legacy schema compatibility: rename or remove old column names.
rename_column($conn, 'products', 'id', 'productID', 'INT NOT NULL AUTO_INCREMENT');
rename_column($conn, 'products', 'product_name', 'productName', 'VARCHAR(255) NOT NULL');
rename_column($conn, 'products', 'created_at', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
rename_column($conn, 'transactions', 'id', 'transactionID', 'INT NOT NULL AUTO_INCREMENT');
rename_column($conn, 'transactions', 'product_id', 'productID', 'INT NULL');
rename_column($conn, 'transactions', 'product_name', 'productName', 'VARCHAR(255) NOT NULL');
rename_column($conn, 'transactions', 'quantity_change', 'quantityChange', 'INT NOT NULL DEFAULT 0');
rename_column($conn, 'transactions', 'quantity_after', 'quantityAfter', 'INT NULL');
rename_column($conn, 'transactions', 'created_at', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
rename_column($conn, 'users', 'id', 'userID', 'INT NOT NULL AUTO_INCREMENT');
rename_column($conn, 'users', 'full_name', 'fullName', 'VARCHAR(80) NOT NULL');
rename_column($conn, 'users', 'created_at', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
drop_column_if_exists($conn, 'products', 'updated_at');
drop_column_if_exists($conn, 'transactions', 'qty');

// Add any columns that are missing from an older local database.
ensure_column($conn, 'products', 'productName', 'VARCHAR(255) NOT NULL DEFAULT ""');
ensure_column($conn, 'products', 'category', 'VARCHAR(120) NOT NULL DEFAULT ""');
ensure_column($conn, 'products', 'quantity', 'INT NOT NULL DEFAULT 0');
ensure_column($conn, 'products', 'price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
ensure_column($conn, 'products', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
ensure_column($conn, 'products', 'dateDeleted', 'DATE DEFAULT NULL');

ensure_column($conn, 'product_sizes', 'productID', 'INT NOT NULL');
ensure_column($conn, 'product_sizes', 'sizeLabel', 'VARCHAR(10) NOT NULL DEFAULT ""');
ensure_column($conn, 'product_sizes', 'quantity', 'INT NOT NULL DEFAULT 0');
ensure_column($conn, 'product_sizes', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

ensure_column($conn, 'transactions', 'productID', 'INT NULL');
ensure_column($conn, 'transactions', 'productName', 'VARCHAR(255) NOT NULL DEFAULT ""');
ensure_column($conn, 'transactions', 'type', 'VARCHAR(30) NOT NULL DEFAULT "updated"');
ensure_column($conn, 'transactions', 'quantityChange', 'INT NOT NULL DEFAULT 0');
ensure_column($conn, 'transactions', 'quantityAfter', 'INT NULL');
ensure_column($conn, 'transactions', 'remarks', 'VARCHAR(255) NULL');
ensure_column($conn, 'transactions', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
ensure_column($conn, 'transactions', 'dateDeleted', 'DATE DEFAULT NULL');

ensure_column($conn, 'users', 'fullName', 'VARCHAR(80) NOT NULL DEFAULT ""');
ensure_column($conn, 'users', 'email', 'VARCHAR(80) NOT NULL DEFAULT "" UNIQUE');
ensure_column($conn, 'users', 'role', "ENUM('admin','staff') NOT NULL DEFAULT 'staff'");
ensure_column($conn, 'users', 'dateCreated', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
ensure_column($conn, 'users', 'dateDeleted', 'DATE DEFAULT NULL');

// Make sure each main table has a valid single-column AUTO_INCREMENT primary key.
ensure_auto_increment_primary_key($conn, 'products', 'productID');
ensure_auto_increment_primary_key($conn, 'transactions', 'transactionID');
ensure_auto_increment_primary_key($conn, 'users', 'userID');
repair_zero_product_size_links($conn);

// Normalize null or invalid data before tightening column definitions.
$conn->query("UPDATE products SET quantity = 0 WHERE quantity IS NULL");
$conn->query("UPDATE products SET price = 0.00 WHERE price IS NULL");
$conn->query("ALTER TABLE products MODIFY quantity INT NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE products MODIFY price DECIMAL(10,2) NOT NULL DEFAULT 0.00");

$conn->query("UPDATE transactions SET type = 'updated' WHERE type IS NULL OR type = ''");
$conn->query("ALTER TABLE transactions MODIFY type VARCHAR(30) NOT NULL DEFAULT 'updated'");
$conn->query("ALTER TABLE transactions MODIFY quantityChange INT NOT NULL DEFAULT 0");

$conn->query("UPDATE users SET username = CONCAT('user', userID) WHERE username IS NULL OR username = ''");
$disabledPassword = $conn->real_escape_string(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
$conn->query("UPDATE users SET password = '$disabledPassword' WHERE password IS NULL OR password = ''");
$conn->query("UPDATE users SET role = 'staff' WHERE role IS NULL OR role NOT IN ('admin', 'staff')");
$conn->query("ALTER TABLE users MODIFY username VARCHAR(80) NOT NULL");
$conn->query("ALTER TABLE users MODIFY email VARCHAR(80) NOT NULL");
$conn->query("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL");
$conn->query("ALTER TABLE users MODIFY role ENUM('admin','staff') NOT NULL DEFAULT 'staff'");

$activeAdminResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND dateDeleted IS NULL");
$activeAdminCount = $activeAdminResult ? (int) $activeAdminResult->fetch_assoc()['total'] : 0;

// Keep at least one active admin account so the app cannot lock itself out.
if ($activeAdminCount === 0) {
    $conn->query("UPDATE users SET role = 'admin' WHERE dateDeleted IS NULL ORDER BY userID ASC LIMIT 1");
}

if (!index_exists($conn, 'users', 'username')) {
    $conn->query("ALTER TABLE users ADD UNIQUE KEY username (username)");
}

// Recreate stored procedures so PHP actions can call consistent database routines.
function recreate_procedure($conn, $name, $sql)
{
    $safeName = preg_replace('/[^A-Za-z0-9_]/', '', $name);
    $conn->query("DROP PROCEDURE IF EXISTS `$safeName`");
    $conn->query($sql);
}

// Create triggers only when missing to avoid dropping active trigger definitions mid-request.
function recreate_trigger($conn, $name, $sql)
{
    $safeName = preg_replace('/[^A-Za-z0-9_]/', '', $name);
    $escapedName = $conn->real_escape_string($safeName);
    $exists = $conn->query("
        SELECT TRIGGER_NAME
        FROM INFORMATION_SCHEMA.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
        AND TRIGGER_NAME = '$escapedName'
        LIMIT 1
    ");

    if ($exists && $exists->num_rows > 0) {
        return;
    }

    $conn->query($sql);
}

// This old procedure name is no longer used.
$conn->query("DROP PROCEDURE IF EXISTS `AddStock`");

// User lookup and account management procedures.
recreate_procedure($conn, 'GetUserByEmail', "
    CREATE PROCEDURE GetUserByEmail(IN p_email VARCHAR(80))
    READS SQL DATA
    BEGIN
        SELECT userID, fullName, username, email, password, role
        FROM users
        WHERE email = p_email AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'CheckUserDuplicate', "
    CREATE PROCEDURE CheckUserDuplicate(IN p_username VARCHAR(80), IN p_email VARCHAR(80))
    READS SQL DATA
    BEGIN
        SELECT userID, fullName, username, email, role
        FROM users
        WHERE (username = p_username OR email = p_email)
        AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_insertUser', "
    CREATE PROCEDURE sp_insertUser(
        IN p_fullName VARCHAR(80),
        IN p_username VARCHAR(80),
        IN p_email VARCHAR(80),
        IN p_password VARCHAR(255),
        IN p_role VARCHAR(20)
    )
    MODIFIES SQL DATA
    BEGIN
        INSERT INTO users (fullName, username, email, password, role)
        VALUES (p_fullName, p_username, p_email, p_password, p_role);

        SELECT LAST_INSERT_ID() AS insertID;
    END
");

recreate_procedure($conn, 'sp_updateUser', "
    CREATE PROCEDURE sp_updateUser(
        IN p_userID INT,
        IN p_fullName VARCHAR(80),
        IN p_username VARCHAR(80),
        IN p_email VARCHAR(80),
        IN p_role VARCHAR(20)
    )
    MODIFIES SQL DATA
    BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email,
            role = p_role
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_updateProfile', "
    CREATE PROCEDURE sp_updateProfile(
        IN p_userID INT,
        IN p_fullName VARCHAR(80),
        IN p_username VARCHAR(80),
        IN p_email VARCHAR(80)
    )
    MODIFIES SQL DATA
    BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_updateProfilePassword', "
    CREATE PROCEDURE sp_updateProfilePassword(
        IN p_userID INT,
        IN p_fullName VARCHAR(80),
        IN p_username VARCHAR(80),
        IN p_email VARCHAR(80),
        IN p_password VARCHAR(255)
    )
    MODIFIES SQL DATA
    BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email,
            password = p_password
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_deleteUser', "
    CREATE PROCEDURE sp_deleteUser(IN p_userID INT)
    MODIFIES SQL DATA
    BEGIN
        UPDATE users
        SET dateDeleted = CURDATE()
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END
");

// Product and transaction procedures used by add/edit/delete/stock actions.
recreate_procedure($conn, 'GetActiveProducts', "
    CREATE PROCEDURE GetActiveProducts()
    READS SQL DATA
    BEGIN
        SELECT productID, productName, category, quantity, price, dateCreated
        FROM products
        WHERE dateDeleted IS NULL
        ORDER BY productName ASC;
    END
");

recreate_procedure($conn, 'sp_insertProduct', "
    CREATE PROCEDURE sp_insertProduct(
        IN p_productName VARCHAR(255),
        IN p_category VARCHAR(120),
        IN p_quantity INT,
        IN p_price DECIMAL(10,2)
    )
    MODIFIES SQL DATA
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM products
            WHERE dateDeleted IS NULL
            AND LOWER(TRIM(productName)) = LOWER(TRIM(p_productName))
            AND LOWER(TRIM(category)) = LOWER(TRIM(p_category))
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product already exists';
        END IF;

        INSERT INTO products(productName, category, quantity, price)
        VALUES (p_productName, p_category, p_quantity, p_price);

        SELECT LAST_INSERT_ID() AS insertID;
    END
");

recreate_procedure($conn, 'sp_updateProductDetails', "
    CREATE PROCEDURE sp_updateProductDetails(
        IN p_productID INT,
        IN p_productName VARCHAR(255),
        IN p_price DECIMAL(10,2)
    )
    MODIFIES SQL DATA
    BEGIN
        DECLARE v_category VARCHAR(120);

        SELECT category
        INTO v_category
        FROM products
        WHERE productID = p_productID AND dateDeleted IS NULL
        LIMIT 1;

        IF EXISTS (
            SELECT 1
            FROM products
            WHERE productID <> p_productID
            AND dateDeleted IS NULL
            AND LOWER(TRIM(productName)) = LOWER(TRIM(p_productName))
            AND LOWER(TRIM(category)) = LOWER(TRIM(v_category))
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product already exists';
        END IF;

        UPDATE products
        SET productName = p_productName,
            price = p_price
        WHERE productID = p_productID AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_updateProductQuantity', "
    CREATE PROCEDURE sp_updateProductQuantity(IN p_productID INT, IN p_quantity INT)
    MODIFIES SQL DATA
    BEGIN
        UPDATE products
        SET quantity = p_quantity
        WHERE productID = p_productID AND dateDeleted IS NULL;
    END
");

recreate_procedure($conn, 'sp_deleteProduct', "
    CREATE PROCEDURE sp_deleteProduct(IN p_productID INT)
    MODIFIES SQL DATA
    BEGIN
        UPDATE products
        SET dateDeleted = CURDATE()
        WHERE productID = p_productID AND dateDeleted IS NULL;

        DELETE FROM product_sizes
        WHERE productID = p_productID;
    END
");

recreate_procedure($conn, 'sp_insertTransaction', "
    CREATE PROCEDURE sp_insertTransaction(
        IN p_productID INT,
        IN p_productName VARCHAR(255),
        IN p_type VARCHAR(30),
        IN p_quantityChange INT,
        IN p_quantityAfter INT,
        IN p_remarks VARCHAR(255)
    )
    MODIFIES SQL DATA
    BEGIN
        INSERT INTO transactions (productID, productName, type, quantityChange, quantityAfter, remarks)
        VALUES (p_productID, p_productName, p_type, p_quantityChange, p_quantityAfter, p_remarks);
    END
");

recreate_procedure($conn, 'GetActiveTransactions', "
    CREATE PROCEDURE GetActiveTransactions()
    READS SQL DATA
    BEGIN
        SELECT transactionID, productID, productName, type, quantityChange, quantityAfter, remarks, dateCreated
        FROM transactions
        WHERE dateDeleted IS NULL
        ORDER BY dateCreated DESC, transactionID DESC;
    END
");

// Product triggers trim inputs and block invalid or duplicate product records.
recreate_trigger($conn, 'trg_products_before_insert', "
    CREATE TRIGGER trg_products_before_insert
    BEFORE INSERT ON products
    FOR EACH ROW
    BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.category = TRIM(NEW.category);

        IF NEW.productName = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product name is required';
        END IF;

        IF NEW.category = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product category is required';
        END IF;

        IF NEW.quantity < 0 OR NEW.price < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product values cannot be negative';
        END IF;
    END
");

recreate_trigger($conn, 'trg_products_before_update', "
    CREATE TRIGGER trg_products_before_update
    BEFORE UPDATE ON products
    FOR EACH ROW
    BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.category = TRIM(NEW.category);

        IF NEW.dateDeleted IS NULL AND NEW.productName = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product name is required';
        END IF;

        IF NEW.dateDeleted IS NULL AND NEW.category = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product category is required';
        END IF;

        IF NEW.quantity < 0 OR NEW.price < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product values cannot be negative';
        END IF;
    END
");

recreate_trigger($conn, 'trg_products_duplicate_before_insert', "
    CREATE TRIGGER trg_products_duplicate_before_insert
    BEFORE INSERT ON products
    FOR EACH ROW
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM products
            WHERE dateDeleted IS NULL
            AND LOWER(TRIM(productName)) = LOWER(TRIM(NEW.productName))
            AND LOWER(TRIM(category)) = LOWER(TRIM(NEW.category))
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product already exists';
        END IF;
    END
");

recreate_trigger($conn, 'trg_products_duplicate_before_update', "
    CREATE TRIGGER trg_products_duplicate_before_update
    BEFORE UPDATE ON products
    FOR EACH ROW
    BEGIN
        IF NEW.dateDeleted IS NULL
            AND (
                LOWER(TRIM(NEW.productName)) <> LOWER(TRIM(OLD.productName))
                OR LOWER(TRIM(NEW.category)) <> LOWER(TRIM(OLD.category))
            )
            AND EXISTS (
                SELECT 1
                FROM products
                WHERE productID <> OLD.productID
                AND dateDeleted IS NULL
                AND LOWER(TRIM(productName)) = LOWER(TRIM(NEW.productName))
                AND LOWER(TRIM(category)) = LOWER(TRIM(NEW.category))
                LIMIT 1
            )
        THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product already exists';
        END IF;
    END
");

// Size triggers keep product_sizes rows valid.
recreate_trigger($conn, 'trg_product_sizes_before_insert', "
    CREATE TRIGGER trg_product_sizes_before_insert
    BEFORE INSERT ON product_sizes
    FOR EACH ROW
    BEGIN
        SET NEW.sizeLabel = TRIM(NEW.sizeLabel);

        IF NEW.productID <= 0 OR NEW.sizeLabel = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid product size';
        END IF;

        IF NEW.quantity < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Size quantity cannot be negative';
        END IF;
    END
");

recreate_trigger($conn, 'trg_product_sizes_before_update', "
    CREATE TRIGGER trg_product_sizes_before_update
    BEFORE UPDATE ON product_sizes
    FOR EACH ROW
    BEGIN
        SET NEW.sizeLabel = TRIM(NEW.sizeLabel);

        IF NEW.productID <= 0 OR NEW.sizeLabel = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid product size';
        END IF;

        IF NEW.quantity < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Size quantity cannot be negative';
        END IF;
    END
");

// User triggers normalize names/emails and reject blank or invalid roles.
recreate_trigger($conn, 'trg_users_before_insert', "
    CREATE TRIGGER trg_users_before_insert
    BEFORE INSERT ON users
    FOR EACH ROW
    BEGIN
        SET NEW.fullName = TRIM(NEW.fullName);
        SET NEW.username = TRIM(NEW.username);
        SET NEW.email = LOWER(TRIM(NEW.email));

        IF NEW.fullName = '' OR NEW.username = '' OR NEW.email = '' OR NEW.password = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User fields are required';
        END IF;

        IF NEW.role NOT IN ('admin', 'staff') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid user role';
        END IF;
    END
");

recreate_trigger($conn, 'trg_users_before_update', "
    CREATE TRIGGER trg_users_before_update
    BEFORE UPDATE ON users
    FOR EACH ROW
    BEGIN
        SET NEW.fullName = TRIM(NEW.fullName);
        SET NEW.username = TRIM(NEW.username);
        SET NEW.email = LOWER(TRIM(NEW.email));

        IF NEW.fullName = '' OR NEW.username = '' OR NEW.email = '' OR NEW.password = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User fields are required';
        END IF;

        IF NEW.role NOT IN ('admin', 'staff') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid user role';
        END IF;
    END
");

// Transaction triggers keep history rows readable and complete.
recreate_trigger($conn, 'trg_transactions_before_insert', "
    CREATE TRIGGER trg_transactions_before_insert
    BEFORE INSERT ON transactions
    FOR EACH ROW
    BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.type = TRIM(NEW.type);

        IF NEW.productName = '' OR NEW.type = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction details are required';
        END IF;
    END
");

recreate_trigger($conn, 'trg_transactions_before_update', "
    CREATE TRIGGER trg_transactions_before_update
    BEFORE UPDATE ON transactions
    FOR EACH ROW
    BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.type = TRIM(NEW.type);

        IF NEW.productName = '' OR NEW.type = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction details are required';
        END IF;
    END
");

?>
