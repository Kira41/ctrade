<?php
header('Content-Type: application/json');
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
try{
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    session_start();
    $adminId=$_SESSION['admin_id']??null;
    if(!$adminId){
        http_response_code(401);
        echo json_encode(['status'=>'error','message'=>'Unauthorized']);
        exit;
    }
    $stmt=$pdo->prepare('SELECT is_admin FROM admins_agents WHERE id=?');
    $stmt->execute([$adminId]);
    $isAdmin=(int)$stmt->fetchColumn();

    $updateVerify=function($uid) use ($pdo){
        $idTypes=['id_front','id_back','selfie'];
        $ph=implode(',',array_fill(0,count($idTypes),'?'));
        $stmt=$pdo->prepare("SELECT status FROM kyc WHERE user_id=? AND file_type IN ($ph)");
        $stmt->execute(array_merge([$uid],$idTypes));
        $statuses=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if($statuses){
            $val=0;
            if(in_array('pending',$statuses)) {
                $val=2;
            } elseif(in_array('approved',$statuses)) {
                $val=1;
            }
            $pdo->prepare('INSERT INTO verification_status (user_id, telechargerlesdocumentsdidentite) VALUES (?,?) ON DUPLICATE KEY UPDATE telechargerlesdocumentsdidentite=VALUES(telechargerlesdocumentsdidentite)')->execute([$uid,$val]);
        }
        $stmt=$pdo->prepare("SELECT status FROM kyc WHERE user_id=? AND file_type='address'");
        $stmt->execute([$uid]);
        $a=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if($a){
            $val=0;
            if(in_array('pending',$a)) {
                $val=2;
            } elseif(in_array('approved',$a)) {
                $val=1;
            }
            $pdo->prepare('INSERT INTO verification_status (user_id, verificationdeladresse) VALUES (?,?) ON DUPLICATE KEY UPDATE verificationdeladresse=VALUES(verificationdeladresse)')->execute([$uid,$val]);
        }
    };
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(isset($_GET['id'])){
            $stmt=$pdo->prepare('SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_data,k.file_type,k.status,k.created_at FROM kyc k JOIN personal_data p ON k.user_id=p.user_id WHERE k.file_id=?');
            $stmt->execute([(int)$_GET['id']]);
            $file=$stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'ok','file'=>$file]);
            exit;
        }
        if(isset($_GET['all'])){
            if($isAdmin!==2){
                http_response_code(403);
                echo json_encode(['status'=>'error','message'=>'Forbidden']);
                exit;
            }
            $stmt=$pdo->query('SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_type,k.created_at,k.status FROM kyc k JOIN personal_data p ON k.user_id=p.user_id ORDER BY k.created_at DESC');
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'ok','kyc'=>$rows]);
        }else{
            if($isAdmin===2){
                $stmt=$pdo->query('SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_type,k.created_at,k.status FROM kyc k JOIN personal_data p ON k.user_id=p.user_id WHERE k.status="pending"');
            }else{
                $sql='SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_type,k.created_at,k.status FROM kyc k JOIN personal_data p ON k.user_id=p.user_id WHERE p.linked_to_id=? AND k.status="pending"';
                $stmt=$pdo->prepare($sql);
                $stmt->execute([$adminId]);
            }
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'ok','kyc'=>$rows]);
        }
    } else {
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input)) throw new Exception('Invalid JSON');
        $id=$input['file_id']??0;
        $status=$input['status']??'';
        if(!$id || !in_array($status,['approved','rejected'])) throw new Exception('Invalid params');
        $stmt=$pdo->prepare('UPDATE kyc SET status=? WHERE file_id=?');
        $stmt->execute([$status,(int)$id]);
        $uidStmt=$pdo->prepare('SELECT user_id FROM kyc WHERE file_id=?');
        $uidStmt->execute([(int)$id]);
        $uid=$uidStmt->fetchColumn();
        if($uid){
            $updateVerify($uid);
            if($status==='approved'){
                $timeNow = date('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $uid,
                        'kyc',
                        'Vérification approuvée',
                        "Votre vérification d'identité a été approuvée.",
                        $timeNow,
                        'alert-success'
                    ]);
            }
        }
        echo json_encode(['status'=>'ok']);
    }
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
