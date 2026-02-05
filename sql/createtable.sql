CREATE TABLE admins_agents (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    email TEXT NOT NULL,
    password TEXT NOT NULL,
    is_admin TINYINT(1) NOT NULL,
    created_by INTEGER NULL,
    UNIQUE(email)
) ENGINE=InnoDB;


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
    emailaddress TEXT,
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
    linked_to_id INTEGER,
    profile_pic MEDIUMTEXT
) ENGINE=InnoDB;

CREATE TABLE transactions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber TEXT,
    type TEXT,
    amount DECIMAL(18,2),
    date TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE(operationNumber),
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    type TEXT,
    title TEXT,
    message TEXT,
    time TEXT,
    alertClass TEXT
) ENGINE=InnoDB;

CREATE TABLE deposits (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT, -- <== modifi\xC3\xA9 ici
    operationNumber TEXT,
    date TEXT,
    amount DECIMAL(18,2),
    method TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE(operationNumber),
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;


CREATE TABLE retraits (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber TEXT,
    date TEXT,
    amount DECIMAL(18,2),
    method TEXT,
    status TEXT,
    statusClass TEXT,
    UNIQUE(operationNumber),
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tradingHistory (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    admin_id BIGINT,
    operationNumber TEXT,
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
    UNIQUE(operationNumber),
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins_agents(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE loginHistory (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    date TEXT,
    ip TEXT,
    device TEXT
) ENGINE=InnoDB;

CREATE TABLE bank_withdrawl_info (
    user_id BIGINT PRIMARY KEY,
    widhrawBankName TEXT,
    widhrawAccountName TEXT,
    widhrawAccountNumber TEXT,
    widhrawIban TEXT,
    widhrawSwiftCode TEXT
) ENGINE=InnoDB;

CREATE TABLE deposit_crypto_address (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    crypto_name TEXT,
    wallet_info TEXT,
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE TABLE kyc (
    file_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    file_name TEXT,
    file_data MEDIUMTEXT,
    file_type VARCHAR(50),
    status TEXT DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE verification_status (
    user_id BIGINT PRIMARY KEY,
    enregistrementducompte TINYINT(1) DEFAULT 0,
    confirmationdeladresseemail TINYINT(1) DEFAULT 0,
    telechargerlesdocumentsdidentite TINYINT(1) DEFAULT 0,
    verificationdeladresse TINYINT(1) DEFAULT 0,
    revisionfinale TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
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
    closed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ftd (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    full_name TEXT,
    email TEXT,
    phone TEXT,
    crm_id TEXT,
    nationality TEXT,
    age TEXT,
    profession TEXT,
    client_difficulty INT,
    client_potential INT,
    technically_comfortable TEXT,
    anydesk_installed TEXT,
    call_duration INT,
    resistance_level TEXT,
    resistance_types TEXT,
    call_notes TEXT,
    general_impression TEXT,
    appointment_set TEXT,
    appointment_datetime TEXT,
    additional_comments TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES personal_data(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
