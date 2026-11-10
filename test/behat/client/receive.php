<?php
use GT\Cipher\{InitVector, Key};
use GT\Cipher\Message\EncryptedMessage;

require dirname(__DIR__, 3) . "/vendor/autoload.php";
$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
$status = (int)$database->query("select status from ClientNotificationResponse")->fetchColumn();
if($status === 200) {
    echo "<html><body>Old client login page</body></html>";
    return;
}

$deleting = isset($_GET["AUTHWAVE_DELETE_USER"]);
$queryKey = $deleting ? "AUTHWAVE_DELETE_USER" : "AUTHWAVE_NEW_USER";
$vectorKey = $deleting ? "AUTHWAVE_DELETE_USER_IV" : "AUTHWAVE_NEW_USER_IV";
$action = $deleting ? "user-deleted" : "user-created";
$vector = (new InitVector())->withBytes(base64_decode($_GET[$vectorKey], true));
$secret = $database->query("select secret from ApplicationDeployment where id = 'managed-development'")->fetchColumn();
$data = json_decode((string)(new EncryptedMessage($_GET[$queryKey], $vector))->decrypt(new Key($secret)),
    flags: JSON_THROW_ON_ERROR);
$query = $database->prepare("select count(*) from User where id = ? and email = ?");
$query->execute([$data->id, $data->email]);

if(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) !== "/login/"
|| $_SERVER["REQUEST_METHOD"] !== "GET" || $data->action !== $action
|| $data->deploymentId !== "managed-development" || abs(time() - $data->issuedAt) > 300
|| (int)$query->fetchColumn() !== 1) {
    http_response_code(400);
    return;
}

$database->prepare("insert into ClientNotificationReceipt(userId,email,iv,action) values (?,?,?,?)")
    ->execute([$data->id, $data->email, $_GET[$vectorKey], $action]);
if($status >= 200 && $status < 300) {
    if($deleting) {
        $database->prepare("delete from ClientUser where id = ?")->execute([$data->id]);
    }
    else {
        $database->prepare("insert or replace into ClientUser(id,email) values (?,?)")->execute([$data->id, $data->email]);
    }
}
header("Authwave-User-Change: received");
http_response_code($status);
