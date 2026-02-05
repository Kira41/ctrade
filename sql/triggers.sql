DELIMITER //

CREATE TRIGGER trg_personal_data_after_insert
AFTER INSERT ON personal_data
FOR EACH ROW
BEGIN
  INSERT IGNORE INTO verification_status
    (user_id, enregistrementducompte, confirmationdeladresseemail,
     telechargerlesdocumentsdidentite, verificationdeladresse, revisionfinale)
  VALUES (NEW.user_id, 1, 1, 0, 0, 2);
END//

CREATE TRIGGER trg_deposits_after_insert
AFTER INSERT ON deposits
FOR EACH ROW
BEGIN
  IF NEW.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) + NEW.amount,
          totalDepots = IFNULL(totalDepots,0) + NEW.amount,
          nbTransactions = IFNULL(nbTransactions,0) + 1
      WHERE user_id = NEW.user_id;
  END IF;
END//

CREATE TRIGGER trg_deposits_after_update
AFTER UPDATE ON deposits
FOR EACH ROW
BEGIN
  IF OLD.status <> 'complet' AND NEW.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) + NEW.amount,
          totalDepots = IFNULL(totalDepots,0) + NEW.amount,
          nbTransactions = IFNULL(nbTransactions,0) + 1
      WHERE user_id = NEW.user_id;
  ELSEIF OLD.status = 'complet' AND NEW.status <> 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) - OLD.amount,
          totalDepots = IFNULL(totalDepots,0) - OLD.amount,
          nbTransactions = IFNULL(nbTransactions,0) - 1
      WHERE user_id = NEW.user_id;
  END IF;
END//

CREATE TRIGGER trg_deposits_after_delete
AFTER DELETE ON deposits
FOR EACH ROW
BEGIN
  IF OLD.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) - OLD.amount,
          totalDepots = IFNULL(totalDepots,0) - OLD.amount,
          nbTransactions = IFNULL(nbTransactions,0) - 1
      WHERE user_id = OLD.user_id;
  END IF;
END//

CREATE TRIGGER trg_retraits_after_insert
AFTER INSERT ON retraits
FOR EACH ROW
BEGIN
  IF NEW.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) - NEW.amount,
          totalRetraits = IFNULL(totalRetraits,0) + NEW.amount,
          nbTransactions = IFNULL(nbTransactions,0) + 1
      WHERE user_id = NEW.user_id;
  END IF;
END//

CREATE TRIGGER trg_retraits_after_update
AFTER UPDATE ON retraits
FOR EACH ROW
BEGIN
  IF OLD.status <> 'complet' AND NEW.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) - NEW.amount,
          totalRetraits = IFNULL(totalRetraits,0) + NEW.amount,
          nbTransactions = IFNULL(nbTransactions,0) + 1
      WHERE user_id = NEW.user_id;
  ELSEIF OLD.status = 'complet' AND NEW.status <> 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) + OLD.amount,
          totalRetraits = IFNULL(totalRetraits,0) - OLD.amount,
          nbTransactions = IFNULL(nbTransactions,0) - 1
      WHERE user_id = NEW.user_id;
  END IF;
END//

CREATE TRIGGER trg_retraits_after_delete
AFTER DELETE ON retraits
FOR EACH ROW
BEGIN
  IF OLD.status = 'complet' THEN
    UPDATE personal_data
      SET balance = IFNULL(balance,0) + OLD.amount,
          totalRetraits = IFNULL(totalRetraits,0) - OLD.amount,
          nbTransactions = IFNULL(nbTransactions,0) - 1
      WHERE user_id = OLD.user_id;
  END IF;
END//

CREATE TRIGGER trg_trades_after_insert
AFTER INSERT ON trades
FOR EACH ROW
BEGIN
  DECLARE op VARCHAR(50);
  DECLARE txStatus TEXT;
  DECLARE txClass  TEXT;

  SET op = CONCAT('T', NEW.id);

  SET txStatus = CASE
      WHEN NEW.status IN ('open', 'pending') THEN 'En cours'
      WHEN NEW.status = 'closed' THEN 'complet'
      ELSE NEW.status
  END;

  SET txClass = CASE
      WHEN NEW.status IN ('open', 'pending') THEN 'bg-warning'
      WHEN NEW.status = 'closed' THEN 'bg-success'
      ELSE 'bg-secondary'
  END;

  INSERT INTO transactions
    (user_id, admin_id, operationNumber, type, amount, date, status, statusClass)
    VALUES (
      NEW.user_id,
      (SELECT linked_to_id FROM personal_data WHERE user_id = NEW.user_id),
      op,
      'Trading',
      NEW.total_value,
      DATE_FORMAT(NEW.created_at, '%Y/%m/%d'),
      txStatus,
      txClass
    )
    ON DUPLICATE KEY UPDATE
      amount = VALUES(amount),
      date = VALUES(date),
      status = VALUES(status),
      statusClass = VALUES(statusClass);
END//

DELIMITER ;
