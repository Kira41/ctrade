INSERT INTO admins_agents (email, password, is_admin, created_by)
VALUES ('admin@scampia.io', 'c1de8b176818ec85532879c60030aedd', 1, NULL);

INSERT INTO personal_data (
    user_id, balance, totalDepots, totalRetraits, nbTransactions,
    fullName, compteverifie, compteverifie01, niveauavance,
    passwordHash, or_p, passwordStrength, passwordStrengthBar,
    emailNotifications, smsNotifications, loginAlerts, transactionAlerts,
    twoFactorAuth, emailaddress, address, phone, dob, nationality, created_at,
    userBankName, userAccountName, userAccountNumber, userIban, userSwiftCode,
    linked_to_id, profile_pic
) VALUES (
    1, 3500, 3000, 1200, '10',
    'Ahmed Kouraychi', 'Vérifié', '1', 'Niveau 2',
    'c1de8b176818ec85532879c60030aedd', 'c1de8b176818ec85532879c60030aedd', 'Fort', '90%',
    '0', '0', '0', '1', '0',
    '41kira41@gmail.com', 'Sousse, Tunisie', '+21690000000',
    '2025-06-11', 'ca', '2025-01-01',
    'Bank of Earth', 'Ahmed Kouraychi',
    'ACC123456', 'IBAN123456', 'SWIFT123', 1, NULL
);

INSERT INTO deposit_crypto_address (user_id, crypto_name, wallet_info) VALUES
    (1, 'Bitcoin', '0xABC123...'),
    (1, 'Tron', 'TRc123456...'),
    (1, 'USDT', 'USDT123...');

INSERT INTO notifications (user_id, type, title, message, time, alertClass) VALUES
    (1, 'info', 'Mise à jour du système', 'Le système sera mis à jour vendredi prochain.', 'Il y a 2 heures', 'alert-info'),
    (1, 'success', 'Dépôt réussi', 'Un montant de 500 $ a été déposé avec succès.', 'Il y a un jour', 'alert-success'),
    (1, 'warning', 'Vérification KYC', 'Merci de vérifier votre identité.', 'Il y a 3 jours', 'alert-warning');

INSERT INTO deposits (user_id, operationNumber, date, amount, method, status, statusClass) VALUES
    (1, 'D1', '2025/06/27', 1500, 'Crypto', 'complet', 'bg-success'),
    (1, 'D2', '2025/06/28', 750, 'Banque', 'complet', 'bg-success'),
    (1, 'D3', '2025/06/29', 500, 'Banque', 'complet', 'bg-success'),
    (1, 'D4', '2025/06/30', 250, 'Carte', 'complet', 'bg-success');

INSERT INTO retraits (user_id, operationNumber, date, amount, method, status, statusClass) VALUES
    (1, 'R1', '2025/06/27', 200, 'Paypal', 'complet', 'bg-success'),
    (1, 'R2', '2025/06/28', 500, 'Banque', 'complet', 'bg-success'),
    (1, 'R3', '2025/06/29', 500, 'Crypto', 'complet', 'bg-success');

INSERT INTO loginHistory (user_id, date, ip, device) VALUES
    (1, '2025/06/09 15:00', '192.168.0.1', 'Chrome - Windows'),
    (1, '2025/06/08 18:20', '192.168.0.2', 'Firefox - Android'),
    (1, '2025/06/07 09:10', '192.168.0.3', 'Safari - iOS'),
    (1, '2025/06/06 23:45', '192.168.0.4', 'Edge - Windows'),
    (1, '2025/06/05 08:30', '192.168.0.5', 'Chrome - macOS');

INSERT INTO bank_withdrawl_info (user_id, widhrawBankName, widhrawAccountName, widhrawAccountNumber, widhrawIban, widhrawSwiftCode)
VALUES (1, 'My Bank', 'Company Ltd', '987654321', 'IBAN987654', 'SWIFT987');

INSERT INTO verification_status (user_id, enregistrementducompte, confirmationdeladresseemail, telechargerlesdocumentsdidentite, verificationdeladresse, revisionfinale)
VALUES (1, 1, 1, 0, 0, 2);

INSERT INTO ftd (
    user_id, full_name, email, phone, crm_id, nationality, age, profession,
    client_difficulty, client_potential, technically_comfortable, anydesk_installed,
    call_duration, resistance_level, resistance_types, call_notes,
    general_impression, appointment_set, appointment_datetime, additional_comments
) VALUES (
    1, 'Ahmed Kouraychi', '41kira41@gmail.com', '+21690000000', 'CRM123', 'ca', '30', 'Trader',
    3, 4, 'yes', 'no',
    20, 'R1', 'Financial,Trust', 'Discussion initiale',
    'Bon potentiel', 'yes', '2025-07-01 10:00', 'N/A'
);
