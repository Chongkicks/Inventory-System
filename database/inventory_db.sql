-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: May 18, 2026 at 04:52 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `inventory_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `CheckUserDuplicate` (IN `p_username` VARCHAR(80), IN `p_email` VARCHAR(80))  READS SQL DATA BEGIN
        SELECT userID, fullName, username, email, role
        FROM users
        WHERE (username = p_username OR email = p_email)
        AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetActiveProducts` ()  READS SQL DATA BEGIN
        SELECT productID, productName, category, quantity, price, dateCreated
        FROM products
        WHERE dateDeleted IS NULL
        ORDER BY productName ASC;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetActiveTransactions` ()  READS SQL DATA BEGIN
        SELECT transactionID, productID, productName, type, quantityChange, quantityAfter, remarks, dateCreated
        FROM transactions
        WHERE dateDeleted IS NULL
        ORDER BY dateCreated DESC, transactionID DESC;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetUserByEmail` (IN `p_email` VARCHAR(80))  READS SQL DATA BEGIN
        SELECT userID, fullName, username, email, password, role
        FROM users
        WHERE email = p_email AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_deleteProduct` (IN `p_productID` INT)  MODIFIES SQL DATA BEGIN
        UPDATE products
        SET dateDeleted = CURDATE()
        WHERE productID = p_productID AND dateDeleted IS NULL;

        DELETE FROM product_sizes
        WHERE productID = p_productID;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_deleteUser` (IN `p_userID` INT)  MODIFIES SQL DATA BEGIN
        UPDATE users
        SET dateDeleted = CURDATE()
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insertProduct` (IN `p_productName` VARCHAR(255), IN `p_category` VARCHAR(120), IN `p_quantity` INT, IN `p_price` DECIMAL(10,2))  MODIFIES SQL DATA BEGIN
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
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insertTransaction` (IN `p_productID` INT, IN `p_productName` VARCHAR(255), IN `p_type` VARCHAR(30), IN `p_quantityChange` INT, IN `p_quantityAfter` INT, IN `p_remarks` VARCHAR(255))  MODIFIES SQL DATA BEGIN
        INSERT INTO transactions (productID, productName, type, quantityChange, quantityAfter, remarks)
        VALUES (p_productID, p_productName, p_type, p_quantityChange, p_quantityAfter, p_remarks);
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insertUser` (IN `p_fullName` VARCHAR(80), IN `p_username` VARCHAR(80), IN `p_email` VARCHAR(80), IN `p_password` VARCHAR(255), IN `p_role` VARCHAR(20))  MODIFIES SQL DATA BEGIN
        INSERT INTO users (fullName, username, email, password, role)
        VALUES (p_fullName, p_username, p_email, p_password, p_role);

        SELECT LAST_INSERT_ID() AS insertID;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_updateProductDetails` (IN `p_productID` INT, IN `p_productName` VARCHAR(255), IN `p_price` DECIMAL(10,2))  MODIFIES SQL DATA BEGIN
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
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_updateProductQuantity` (IN `p_productID` INT, IN `p_quantity` INT)  MODIFIES SQL DATA BEGIN
        UPDATE products
        SET quantity = p_quantity
        WHERE productID = p_productID AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_updateProfile` (IN `p_userID` INT, IN `p_fullName` VARCHAR(80), IN `p_username` VARCHAR(80), IN `p_email` VARCHAR(80))  MODIFIES SQL DATA BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_updateProfilePassword` (IN `p_userID` INT, IN `p_fullName` VARCHAR(80), IN `p_username` VARCHAR(80), IN `p_email` VARCHAR(80), IN `p_password` VARCHAR(255))  MODIFIES SQL DATA BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email,
            password = p_password
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_updateUser` (IN `p_userID` INT, IN `p_fullName` VARCHAR(80), IN `p_username` VARCHAR(80), IN `p_email` VARCHAR(80), IN `p_role` VARCHAR(20))  MODIFIES SQL DATA BEGIN
        UPDATE users
        SET fullName = p_fullName,
            username = p_username,
            email = p_email,
            role = p_role
        WHERE userID = p_userID AND dateDeleted IS NULL;
    END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `productID` int(11) NOT NULL,
  `productName` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lowStockThreshold` int(11) NOT NULL DEFAULT 5,
  `dateDeleted` date DEFAULT NULL,
  `category` varchar(120) NOT NULL DEFAULT '',
  `uniformSize` varchar(30) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`productID`, `productName`, `quantity`, `price`, `dateCreated`, `lowStockThreshold`, `dateDeleted`, `category`, `uniformSize`) VALUES
(20, '1st Year PE Uniform Bottom', 13, 500.00, '2026-05-18 14:18:35', 5, NULL, 'Uniform', ''),
(21, '1st Year PE Uniform Top', 10, 450.00, '2026-05-18 14:19:12', 5, NULL, 'Uniform', ''),
(23, 'BEED ID Lace', 9, 100.00, '2026-05-18 14:22:23', 5, NULL, 'ID lace', ''),
(24, 'BEED Men’s Bottom', 29, 480.00, '2026-05-18 14:23:10', 5, NULL, 'Uniform', ''),
(25, 'BEED Men’s Top', 71, 400.00, '2026-05-18 14:23:45', 5, NULL, 'Uniform', ''),
(26, 'BEED Women’s Bottom', 88, 400.00, '2026-05-18 14:24:35', 5, NULL, 'Uniform', ''),
(27, 'BSBA ID Lace', 26, 100.00, '2026-05-18 14:25:01', 5, NULL, 'ID lace', ''),
(28, 'BSBA School Logo Patch', 12, 40.00, '2026-05-18 14:28:41', 5, NULL, 'Logo', ''),
(29, 'BSBA Men’s Top', 85, 400.00, '2026-05-18 14:29:08', 5, NULL, 'Uniform', ''),
(30, 'BSBA Men’s Bottom', 83, 400.00, '2026-05-18 14:29:44', 5, NULL, 'Uniform', ''),
(31, 'BSIT ID Lace', 40, 100.00, '2026-05-18 14:30:48', 5, NULL, 'ID lace', ''),
(32, 'BSBA Women’s Top', 101, 400.00, '2026-05-18 14:31:15', 5, NULL, 'Uniform', ''),
(33, 'BSBA Women’s Bottom', 97, 400.00, '2026-05-18 14:31:42', 5, NULL, 'Uniform', '');

--
-- Triggers `products`
--
DELIMITER $$
CREATE TRIGGER `trg_products_before_insert` BEFORE INSERT ON `products` FOR EACH ROW BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.category = TRIM(NEW.category);

        IF NEW.productName = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product name is required';
        END IF;

        IF NEW.category = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product category is required';
        END IF;

        IF NEW.quantity < 0 OR NEW.price < 0 OR NEW.lowStockThreshold < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product values cannot be negative';
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_products_before_update` BEFORE UPDATE ON `products` FOR EACH ROW BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.category = TRIM(NEW.category);

        IF NEW.dateDeleted IS NULL AND NEW.productName = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product name is required';
        END IF;

        IF NEW.dateDeleted IS NULL AND NEW.category = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product category is required';
        END IF;

        IF NEW.quantity < 0 OR NEW.price < 0 OR NEW.lowStockThreshold < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product values cannot be negative';
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_products_duplicate_before_insert` BEFORE INSERT ON `products` FOR EACH ROW BEGIN
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
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_products_duplicate_before_update` BEFORE UPDATE ON `products` FOR EACH ROW BEGIN
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
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `product_sizes`
--

CREATE TABLE `product_sizes` (
  `productSizeID` int(11) NOT NULL,
  `productID` int(11) NOT NULL,
  `sizeLabel` varchar(10) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_sizes`
--

INSERT INTO `product_sizes` (`productSizeID`, `productID`, `sizeLabel`, `quantity`, `dateCreated`) VALUES
(4, 6, 'XS', 1, '2026-05-16 16:18:57'),
(5, 6, 'S', 1, '2026-05-16 16:18:57'),
(6, 6, 'M', 1, '2026-05-16 16:18:57'),
(7, 6, 'L', 1, '2026-05-16 16:18:57'),
(8, 6, 'XL', 1, '2026-05-16 16:18:57'),
(9, 6, 'XXL', 1, '2026-05-16 16:18:57'),
(16, 8, 'XS', 10, '2026-05-16 17:40:20'),
(17, 8, 'S', 6, '2026-05-16 17:40:20'),
(18, 8, 'L', 5, '2026-05-16 17:40:20'),
(19, 8, 'XL', 6, '2026-05-16 17:40:20'),
(20, 8, 'XXL', 1, '2026-05-16 17:40:20'),
(24, 13, 'S', 1, '2026-05-17 16:46:46'),
(25, 14, 'XL', 1, '2026-05-17 16:47:34'),
(26, 15, 'S', 2, '2026-05-17 16:50:00'),
(27, 11, 'XS', 4, '2026-05-17 17:08:23'),
(35, 16, 'M', 1, '2026-05-18 13:26:52'),
(36, 16, 'XXL', 4, '2026-05-18 13:26:52'),
(37, 20, 'XS', 3, '2026-05-18 14:18:35'),
(38, 20, 'S', 2, '2026-05-18 14:18:35'),
(39, 20, 'M', 2, '2026-05-18 14:18:35'),
(40, 20, 'L', 2, '2026-05-18 14:18:35'),
(41, 20, 'XL', 2, '2026-05-18 14:18:35'),
(42, 20, 'XXL', 2, '2026-05-18 14:18:35'),
(43, 21, 'XS', 2, '2026-05-18 14:19:12'),
(44, 21, 'S', 1, '2026-05-18 14:19:12'),
(45, 21, 'M', 2, '2026-05-18 14:19:12'),
(46, 21, 'L', 2, '2026-05-18 14:19:12'),
(47, 21, 'XL', 1, '2026-05-18 14:19:12'),
(48, 21, 'XXL', 2, '2026-05-18 14:19:12'),
(49, 24, 'XS', 5, '2026-05-18 14:23:10'),
(50, 24, 'S', 5, '2026-05-18 14:23:10'),
(51, 24, 'M', 5, '2026-05-18 14:23:10'),
(52, 24, 'L', 6, '2026-05-18 14:23:10'),
(53, 24, 'XL', 4, '2026-05-18 14:23:10'),
(54, 24, 'XXL', 4, '2026-05-18 14:23:10'),
(55, 25, 'XS', 12, '2026-05-18 14:23:45'),
(56, 25, 'S', 18, '2026-05-18 14:23:45'),
(57, 25, 'M', 19, '2026-05-18 14:23:45'),
(58, 25, 'L', 9, '2026-05-18 14:23:45'),
(59, 25, 'XL', 13, '2026-05-18 14:23:45'),
(60, 26, 'XS', 25, '2026-05-18 14:24:35'),
(61, 26, 'S', 15, '2026-05-18 14:24:35'),
(62, 26, 'M', 13, '2026-05-18 14:24:35'),
(63, 26, 'L', 11, '2026-05-18 14:24:35'),
(64, 26, 'XL', 13, '2026-05-18 14:24:35'),
(65, 26, 'XXL', 11, '2026-05-18 14:24:35'),
(66, 29, 'XS', 14, '2026-05-18 14:29:08'),
(67, 29, 'S', 19, '2026-05-18 14:29:08'),
(68, 29, 'M', 15, '2026-05-18 14:29:08'),
(69, 29, 'L', 16, '2026-05-18 14:29:08'),
(70, 29, 'XL', 10, '2026-05-18 14:29:08'),
(71, 29, 'XXL', 11, '2026-05-18 14:29:08'),
(72, 30, 'XS', 11, '2026-05-18 14:29:44'),
(73, 30, 'S', 18, '2026-05-18 14:29:44'),
(74, 30, 'M', 13, '2026-05-18 14:29:44'),
(75, 30, 'L', 14, '2026-05-18 14:29:44'),
(76, 30, 'XL', 14, '2026-05-18 14:29:44'),
(77, 30, 'XXL', 13, '2026-05-18 14:29:44'),
(78, 32, 'XS', 17, '2026-05-18 14:31:15'),
(79, 32, 'S', 27, '2026-05-18 14:31:15'),
(80, 32, 'M', 13, '2026-05-18 14:31:15'),
(81, 32, 'L', 14, '2026-05-18 14:31:15'),
(82, 32, 'XL', 12, '2026-05-18 14:31:15'),
(83, 32, 'XXL', 18, '2026-05-18 14:31:15'),
(84, 33, 'XS', 17, '2026-05-18 14:31:42'),
(85, 33, 'S', 13, '2026-05-18 14:31:42'),
(86, 33, 'M', 25, '2026-05-18 14:31:42'),
(87, 33, 'L', 12, '2026-05-18 14:31:42'),
(88, 33, 'XL', 13, '2026-05-18 14:31:42'),
(89, 33, 'XXL', 17, '2026-05-18 14:31:42');

--
-- Triggers `product_sizes`
--
DELIMITER $$
CREATE TRIGGER `trg_product_sizes_before_insert` BEFORE INSERT ON `product_sizes` FOR EACH ROW BEGIN
        SET NEW.sizeLabel = TRIM(NEW.sizeLabel);

        IF NEW.productID <= 0 OR NEW.sizeLabel = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid product size';
        END IF;

        IF NEW.quantity < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Size quantity cannot be negative';
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_product_sizes_before_update` BEFORE UPDATE ON `product_sizes` FOR EACH ROW BEGIN
        SET NEW.sizeLabel = TRIM(NEW.sizeLabel);

        IF NEW.productID <= 0 OR NEW.sizeLabel = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid product size';
        END IF;

        IF NEW.quantity < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Size quantity cannot be negative';
        END IF;
    END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transactionID` int(11) NOT NULL,
  `productID` int(11) DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'updated',
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `productName` varchar(255) NOT NULL,
  `quantityChange` int(11) NOT NULL DEFAULT 0,
  `quantityAfter` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `dateDeleted` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transactionID`, `productID`, `type`, `dateCreated`, `productName`, `quantityChange`, `quantityAfter`, `remarks`, `dateDeleted`) VALUES
(1, 0, 'created', '2026-05-16 16:18:57', 'BSIT Id Lace', 5, 5, 'Product added', NULL),
(2, 0, 'created', '2026-05-16 16:18:57', 'BSIT TOP', 10, 10, 'Product added', NULL),
(3, 0, 'created', '2026-05-16 16:18:57', 'BSIT TOP', 10, 10, 'Product added', NULL),
(4, 0, 'deleted', '2026-05-16 16:18:57', 'BSIT Id Lace', 0, 0, 'Product deleted', NULL),
(5, 0, 'created', '2026-05-16 16:18:57', 'BSIT Id Lace', 10, 10, 'Product added', NULL),
(6, 0, 'created', '2026-05-16 16:18:57', 'PE tshirt', 6, 6, 'Product added', NULL),
(7, 0, 'deleted', '2026-05-16 16:18:57', 'BSIT Id Lace', 0, 0, 'Product deleted', NULL),
(8, 0, 'created', '2026-05-16 16:18:57', 'PE tshirt', 6, 6, 'Product added', NULL),
(9, 0, 'created', '2026-05-16 16:18:57', 'BSIT Id Lace', 16, 16, 'Product added', NULL),
(10, 8, 'created', '2026-05-16 16:25:35', 'PE shorts', 10, 10, 'Product added', NULL),
(11, 9, 'created', '2026-05-16 16:26:22', 'CICT LOGO', 10, 10, 'Product added', NULL),
(12, 7, 'deleted', '2026-05-16 16:40:19', 'BSIT Id Lace', 0, 0, 'Product deleted', NULL),
(13, 8, 'stock_in', '2026-05-16 17:03:13', 'PE shorts - S', 6, 6, 'Total stock: 16', NULL),
(14, 8, 'stock_in', '2026-05-16 17:03:13', 'PE shorts - XXL', 1, 1, 'Total stock: 17', NULL),
(15, 8, 'updated', '2026-05-16 17:06:31', 'PE Shorts', 0, 17, 'Product details updated', NULL),
(16, 8, 'stock_in', '2026-05-16 17:40:20', 'PE Shorts - L', 5, 5, 'Total stock: 28', NULL),
(17, 8, 'stock_in', '2026-05-16 17:40:20', 'PE Shorts - XL', 6, 6, 'Total stock: 28', NULL),
(18, 9, 'stock_out', '2026-05-16 17:46:05', 'CICT LOGO', -5, 5, '', NULL),
(19, 9, 'updated', '2026-05-17 10:00:06', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(20, 9, 'updated', '2026-05-17 10:00:43', 'CICT LACE', 0, 5, 'Product details updated', NULL),
(21, 9, 'updated', '2026-05-17 10:05:54', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(22, 9, 'updated', '2026-05-17 10:06:12', 'CICT LOGO1', 0, 5, 'Product details updated', NULL),
(23, 9, 'updated', '2026-05-17 10:08:20', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(24, 9, 'updated', '2026-05-17 10:16:56', 'CICT LOGON', 0, 5, 'Product details updated', NULL),
(25, 9, 'updated', '2026-05-17 10:20:43', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(26, 9, 'updated', '2026-05-17 10:23:54', 'CICT LOG', 0, 5, 'Product details updated', NULL),
(27, 10, 'created', '2026-05-17 10:25:35', 'UNIFORM BEED', 1, 1, 'Product added', NULL),
(28, 10, 'deleted', '2026-05-17 10:25:59', 'UNIFORM BEED', 0, 0, 'Product deleted', NULL),
(29, 9, 'updated', '2026-05-17 10:26:20', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(30, 9, 'updated', '2026-05-17 10:32:03', 'CICT LOG', 0, 5, 'Product details updated', NULL),
(31, 9, 'updated', '2026-05-17 10:32:25', 'CICT LOGO', 0, 5, 'Product details updated', NULL),
(32, 11, 'created', '2026-05-17 10:35:03', 'BSBA UNIFORM', 2, 2, 'Product added', NULL),
(33, 11, 'updated', '2026-05-17 10:39:02', 'BSBA UNIFORMS', 0, 2, 'Product details updated', NULL),
(34, 11, 'updated', '2026-05-17 11:12:46', 'BSBA UNIFORM', 0, 2, 'Product details updated', NULL),
(35, 11, 'updated', '2026-05-17 15:20:53', 'BSBA UNIFORMS', 0, 2, 'Product details updated', NULL),
(36, 11, 'updated', '2026-05-17 15:21:23', 'BSBA UNIFORM', 0, 2, 'Product details updated', NULL),
(37, 11, 'stock_out', '2026-05-17 15:38:33', 'BSBA UNIFORM - XS', -1, 1, 'Total stock: 1', NULL),
(38, 9, 'stock_in', '2026-05-17 16:32:51', 'CICT LOGO', 5, 10, 'Second Deliver', NULL),
(39, 9, 'stock_in', '2026-05-17 16:40:27', 'CICT LOGO', 2, 12, '', NULL),
(40, 12, 'created', '2026-05-17 16:46:05', 'BSBA UNIFORM', 0, 0, 'Product added', NULL),
(41, 13, 'created', '2026-05-17 16:46:46', 'BSBA UNIFORM', 1, 1, 'Product added', NULL),
(42, 14, 'created', '2026-05-17 16:47:34', 'BSBA UNIFORM', 1, 1, 'Product added', NULL),
(43, 15, 'created', '2026-05-17 16:50:00', 'BSBA UNIFORM', 2, 2, 'Product added', NULL),
(44, 11, 'stock_in', '2026-05-17 17:08:23', 'BSBA UNIFORM - XS', 3, 4, 'Total stock: 4', NULL),
(45, 9, 'deleted', '2026-05-17 17:13:45', 'CICT LOGO', 0, 0, 'Product deleted', NULL),
(46, 16, 'created', '2026-05-18 01:17:55', 'BSBA UNIFORM', 22, 22, 'Product added', NULL),
(47, 17, 'created', '2026-05-18 01:21:46', 'CICT LOGO', 17, 17, 'Product added', NULL),
(48, 18, 'created', '2026-05-18 01:27:18', 'PE tshirt', 5, 5, 'Product added', NULL),
(49, 16, 'updated', '2026-05-18 01:27:37', 'BSBA UNIFORMS', 0, 22, 'Product details updated', NULL),
(50, 19, 'created', '2026-05-18 01:28:08', 'NEUST LOGO', 29, 29, 'Product added', NULL),
(51, 16, 'updated', '2026-05-18 01:33:22', 'BSBA UNIFORM', 0, 22, 'Product details updated', NULL),
(52, 17, 'stock_out', '2026-05-18 13:25:31', 'CICT LOGO', -3, 14, 'hehe', NULL),
(53, 16, 'stock_out', '2026-05-18 13:26:52', 'BSBA UNIFORM - XS', -2, 0, 'hehe', NULL),
(54, 16, 'stock_out', '2026-05-18 13:26:52', 'BSBA UNIFORM - S', -4, 0, 'hehe', NULL),
(55, 16, 'stock_out', '2026-05-18 13:26:52', 'BSBA UNIFORM - M', -4, 1, 'hehe', NULL),
(56, 16, 'stock_out', '2026-05-18 13:26:52', 'BSBA UNIFORM - L', -4, 0, 'hehe', NULL),
(57, 16, 'stock_out', '2026-05-18 13:26:52', 'BSBA UNIFORM - XL', -3, 0, 'hehe', NULL),
(58, 20, 'created', '2026-05-18 14:18:35', '1st Year PE Uniform Bottom', 13, 13, 'Product added', NULL),
(59, 21, 'created', '2026-05-18 14:19:12', '1st Year PE Uniform Top', 10, 10, 'Product added', NULL),
(60, 22, 'created', '2026-05-18 14:19:42', 'BEED ID Lace', 10, 10, 'Product added', NULL),
(61, 18, 'deleted', '2026-05-18 14:21:10', 'PE tshirt', 0, 0, 'Product deleted', NULL),
(62, 19, 'deleted', '2026-05-18 14:21:27', 'NEUST LOGO', 0, 0, 'Product deleted', NULL),
(63, 22, 'deleted', '2026-05-18 14:21:45', 'BEED ID Lace', 0, 0, 'Product deleted', NULL),
(64, 23, 'created', '2026-05-18 14:22:23', 'BEED ID Lace', 9, 9, 'Product added', NULL),
(65, 24, 'created', '2026-05-18 14:23:10', 'BEED Men’s Bottom', 29, 29, 'Product added', NULL),
(66, 25, 'created', '2026-05-18 14:23:45', 'BEED Men’s Top', 71, 71, 'Product added', NULL),
(67, 26, 'created', '2026-05-18 14:24:35', 'BEED Women’s Bottom', 88, 88, 'Product added', NULL),
(68, 27, 'created', '2026-05-18 14:25:01', 'BSBA ID Lace', 26, 26, 'Product added', NULL),
(69, 28, 'created', '2026-05-18 14:28:41', 'BSBA School Logo Patch', 12, 12, 'Product added', NULL),
(70, 29, 'created', '2026-05-18 14:29:08', 'BSBA Men’s Top', 85, 85, 'Product added', NULL),
(71, 30, 'created', '2026-05-18 14:29:44', 'BSBA Men’s Bottom', 83, 83, 'Product added', NULL),
(72, 31, 'created', '2026-05-18 14:30:48', 'BSIT ID Lace', 40, 40, 'Product added', NULL),
(73, 32, 'created', '2026-05-18 14:31:15', 'BSBA Women’s Top', 101, 101, 'Product added', NULL),
(74, 33, 'created', '2026-05-18 14:31:42', 'BSBA Women’s Bottom', 97, 97, 'Product added', NULL);

--
-- Triggers `transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_transactions_before_insert` BEFORE INSERT ON `transactions` FOR EACH ROW BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.type = TRIM(NEW.type);

        IF NEW.productName = '' OR NEW.type = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction details are required';
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_transactions_before_update` BEFORE UPDATE ON `transactions` FOR EACH ROW BEGIN
        SET NEW.productName = TRIM(NEW.productName);
        SET NEW.type = TRIM(NEW.type);

        IF NEW.productName = '' OR NEW.type = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction details are required';
        END IF;
    END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userID` int(11) NOT NULL,
  `fullName` varchar(80) NOT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dateDeleted` date DEFAULT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`userID`, `fullName`, `username`, `email`, `password`, `dateCreated`, `dateDeleted`, `role`) VALUES
(3, 'Joshua Trajano', 'joshua1234', 'trajano@gmail.com', '$2y$10$0y7fGSXtaGS15FL9PNYLBuPXzf.T4eV1R6UnR5R6TzDy0kDgV.Jp6', '2026-05-16 12:56:13', NULL, 'staff'),
(4, 'Ian Carlo Dela Rosa', 'iancarlo123', 'iancarlodelarosa7@gmail.com', '$2y$10$QC01p0KADRcfUw0KFvTx/Oy9146w2ne3Jbx8623vHSyeI.i4OH5TS', '2026-05-18 14:15:17', NULL, 'staff'),
(6, 'Trajano', 'Trajano', 'chongkicks2@gmail.com', '$2y$10$//jTP9uHR0wGXzrgduBCV.OP9e92uCUVPT0LGAxFBcvFQVaWmHWse', '2026-05-16 15:27:13', NULL, 'staff'),
(8, 'joshua kristoffer trajano', 'Joshua', 'jgtrajano222@gmail.com', '$2y$10$v3n0Fb.UBOGbgQlB.RJiPeEAo9jR.zf.Izs.Nekz6KCmV1Z1p7g.q', '2026-05-17 16:12:43', NULL, 'staff'),
(9, 'System Admin', 'admin', 'admin@gmail.com', '$2y$10$QyIE4ye/MFUeRaXH/.m9ku2rum9KtUIrZMAmgf.JNxvTkQ4yQBy3S', '2026-05-17 15:14:45', NULL, 'admin'),
(10, 'Carey Bustamanye yeah', 'carey', 'carey@gmail.com', '$2y$10$jvIyinY1O8lohcNvySuiS.GUUP8nJvtyeFmtfyZdwdfvJB9c8lmZS', '2026-05-18 11:57:02', '2026-05-18', 'staff'),
(11, 'carey reyes', 'CAREY27', 'reyescarey27@gmail.com', '$2y$10$B18WfHtMnR7b7XPt9.ZTj.f.QcawT7Qme3BQ.pS1Aiq23NpPSxz.2', '2026-05-17 16:03:18', NULL, 'staff'),
(12, 'joshua trajano', 'User', 'careybustamanye@gmail.com', '$2y$10$CRxwIGggDZ5ItAeCmEWcauAuIEu3jMzlxAi0ywEotXmW3oB/ehNoO', '2026-05-17 16:29:20', NULL, 'staff'),
(13, 'aj ignacio', 'ajignacio', 'aj@gmail.com', '$2y$10$ZgJWK.4ZEYN116ekbWRPROEZMWFSpD/MfJtWHbDrCNC6XnEO8lYUC', '2026-05-17 16:31:19', NULL, 'staff'),
(14, 'Aj Ignacio', 'AJ123', 'ajignacio3@gmail.com', '$2y$10$hMR1AbjNlxi.gkT8.gTJp.BJnwyX0a/BPgh8Bifw0i.eXeCT0aCOC', '2026-05-17 17:07:51', NULL, 'admin'),
(15, 'Ronald batongbakal', 'ronald13', '09656225304@gmail.com', '$2y$10$fXw1ypuaSr9Jn.7nBaUJSOuimp7VPhsSQ.xXyKNmx0jA2RR7RXtM6', '2026-05-18 13:27:38', '2026-05-18', 'staff'),
(16, 'Joshua kulotsie', 'Kulot2727', 'kulot14@gmail.com', '$2y$10$IKNorhPPcLH1d4zNqEEKoeQcQMrtSJ3AKvald/vDWw0xBBt5KCLhu', '2026-05-18 13:24:48', NULL, 'staff');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_users_before_insert` BEFORE INSERT ON `users` FOR EACH ROW BEGIN
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
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_users_before_update` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN
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
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`productID`);

--
-- Indexes for table `product_sizes`
--
ALTER TABLE `product_sizes`
  ADD PRIMARY KEY (`productSizeID`),
  ADD UNIQUE KEY `product_size_unique` (`productID`,`sizeLabel`),
  ADD KEY `productID` (`productID`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transactionID`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userID`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `productID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `product_sizes`
--
ALTER TABLE `product_sizes`
  MODIFY `productSizeID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transactionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
