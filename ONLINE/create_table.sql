-- Charset note: If utf8mb4 isn't available in your MariaDB 5.5 build, replace with utf8.
-- SET NAMES utf8mb4;

-- =========================
-- Drop in dependency order
-- =========================
DROP TABLE IF EXISTS deposit_crypto_address;
DROP TABLE IF EXISTS kyc;
DROP TABLE IF EXISTS verification_status;
DROP TABLE IF EXISTS trades;
DROP TABLE IF EXISTS ftd;
DROP TABLE IF EXISTS retraits;
DROP TABLE IF EXISTS deposits;
DROP TABLE IF EXISTS tradingHistory;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS loginHistory;
DROP TABLE IF EXISTS bank_withdrawl_info;
DROP TABLE IF EXISTS personal_data;
DROP TABLE IF EXISTS admins_agents;

-- =========================
-- Create parents first
-- =========================
CREATE TABLE admins_agents (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL,
    created_by BIGINT NULL,
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE personal_data (
    user_id BIGINT PRIMARY KEY,
    balance DECIMAL(18,2),
    totalDepots DECIMAL(18,2),
    totalRetraits DECIMAL(18,2),
    nbTransactions TEXT,
    fullName TEXT,
    compteverifie TEXT,
    compteverifie01 TEXT,
    niveauavance TEXT,
    passwordHash TEXT,
    or_p TEXT,
    passwordStrength TEXT,
    passwordStrengthBar TEXT,
    emailNotifications TEXT,
    smsNotifications TEXT,
    loginAlerts TEXT,
    transactionAlerts TEXT,
    twoFactorAuth TEXT,
    emailaddress VARCHAR(191),
    address TEXT,
    phone TEXT,
    dob TEXT,
    nationality TEXT,
    created_at TEXT,
    userBankName TEXT,
    userAccountName TEXT,
    userAccountNumber TEXT,
    userIban TEXT,
    userSwiftCode TEXT,
    linked_to_id BIGINT,
    profile_pic MEDIUMTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================
-- Children / related tables
-- =========================
CREATE TABLE bank_withdrawl_info (
    user_id BIGINT PRIMARY KEY,
    widhrawBankName TEXT,
    widhrawAccountName TEXT,
    widhrawAccountNumber TEXT,
    widhrawIban TEXT,
    widhrawSwiftCode TEXT,
    CONSTRAINT fk_bank_withdrawl_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    type TEXT,
    title TEXT,
    message TEXT,
    time TEXT,
    alertClass TEXT,
    KEY idx_notifications_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber VARCHAR(191),
    type TEXT,
    amount DECIMAL(18,2),
    date TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE KEY uq_transactions_op (operationNumber),
    KEY idx_transactions_user (user_id),
    KEY idx_transactions_admin (admin_id),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_admin
        FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deposits (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber VARCHAR(191),
    date TEXT,
    amount DECIMAL(18,2),
    method TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE KEY uq_deposits_op (operationNumber),
    KEY idx_deposits_user (user_id),
    KEY idx_deposits_admin (admin_id),
    CONSTRAINT fk_deposits_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_deposits_admin
        FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retraits (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber VARCHAR(191),
    date TEXT,
    amount DECIMAL(18,2),
    method TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE KEY uq_retraits_op (operationNumber),
    KEY idx_retraits_user (user_id),
    KEY idx_retraits_admin (admin_id),
    CONSTRAINT fk_retraits_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_retraits_admin
        FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tradingHistory (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber VARCHAR(191),
    temps TEXT,
    paireDevises TEXT,
    type TEXT,
    statutTypeClass TEXT,
    montant DECIMAL(20,10),
    prix DECIMAL(20,10),
    statut TEXT,
    statutClass TEXT,
    profitPerte DECIMAL(20,10),
    profitClass TEXT,
    details TEXT,
    UNIQUE KEY uq_tradingHistory_op (operationNumber),
    KEY idx_tradingHistory_user (user_id),
    KEY idx_tradingHistory_admin (admin_id),
    CONSTRAINT fk_tradingHistory_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tradingHistory_admin
        FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loginHistory (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    date TEXT,
    ip VARCHAR(191),
    device VARCHAR(191),
    KEY idx_loginHistory_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deposit_crypto_address (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    crypto_name VARCHAR(191),
    wallet_info TEXT,
    KEY idx_deposit_crypto_user (user_id),
    CONSTRAINT fk_deposit_crypto_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed: TEXT cannot have DEFAULT; use ENUM (or VARCHAR) with DEFAULT
CREATE TABLE kyc (
    file_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    file_name VARCHAR(191),
    file_data MEDIUMTEXT,
    file_type VARCHAR(50),
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kyc_user (user_id),
    CONSTRAINT fk_kyc_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE verification_status (
    user_id BIGINT PRIMARY KEY,
    enregistrementducompte TINYINT(1) DEFAULT 0,
    confirmationdeladresseemail TINYINT(1) DEFAULT 0,
    telechargerlesdocumentsdidentite TINYINT(1) DEFAULT 0,
    verificationdeladresse TINYINT(1) DEFAULT 0,
    revisionfinale TINYINT(1) DEFAULT 0,
    CONSTRAINT fk_verification_status_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trades (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    pair VARCHAR(20),
    side ENUM('buy','sell'),
    quantity DECIMAL(20,10),
    price DECIMAL(20,10),
    total_value DECIMAL(20,10),
    fee DECIMAL(20,10) DEFAULT 0,
    profit_loss DECIMAL(20,10) DEFAULT 0,
    status ENUM('open','closed','pending') DEFAULT 'open',
    type_order ENUM('market','limit') DEFAULT 'market',
    stop_price DECIMAL(20,10),
    close_price DECIMAL(20,10),
    closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_trades_user (user_id),
    CONSTRAINT fk_trades_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ftd (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    full_name VARCHAR(191),
    email VARCHAR(191),
    phone VARCHAR(191),
    crm_id VARCHAR(191),
    nationality VARCHAR(191),
    age VARCHAR(50),
    profession VARCHAR(191),
    client_difficulty INT,
    client_potential INT,
    technically_comfortable VARCHAR(191),
    anydesk_installed VARCHAR(50),
    call_duration INT,
    resistance_level VARCHAR(191),
    resistance_types VARCHAR(191),
    call_notes TEXT,
    general_impression TEXT,
    appointment_set VARCHAR(50),
    appointment_datetime VARCHAR(191),
    additional_comments TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ftd_user (user_id),
    CONSTRAINT fk_ftd_user
        FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
