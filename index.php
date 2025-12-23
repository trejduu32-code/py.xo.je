<?php
/* ================= CONFIG ================= */
define('DB_FILE', __DIR__ . '/urlshortener.db');
define('SITE_NAME', 'py.xo.je');
define('SITE_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('LOGO', 'https://trejduu32-code.github.io/xq/py.png');

/* ================= DATABASE ================= */
$db = new SQLite3(DB_FILE);
$db->exec("
CREATE TABLE IF NOT EXISTS links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE,
    url TEXT NOT NULL,
    clicks INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
");

/* ================= HELPERS ================= */
function clean($s){
    return preg_replace('/[^a-zA-Z0-9_-]/','',$s);
}
function genCode($l=5){
    $c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($c),0,$l);
}
function isBot(){
    $ua=strtolower($_SERVER['HTTP_USER_AGENT']??'');
    return preg_match('/instagram|facebook|twitter|discord|bot|crawler|spider/',$ua);
}

/* ================= PREVIEW MODE (+) ================= */
$rawPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (str_ends_with($rawPath,'+')) {
    $code = rtrim($rawPath,'+');

    $p=$db->prepare("SELECT * FROM links WHERE code=?");
    $p->bindValue(1,$code);
    $d=$p->execute()->fetchArray(SQLITE3_ASSOC);

    if(!$d){ http_response_code(404); exit('Link not found'); }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Preview – <?=$code?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Arial;background:#fff;text-align:center;margin-top:80px}
.box{display:inline-block;border:1px solid #ddd;padding:25px 35px;border-radius:6px}
.url{word-break:break-all;margin:10px 0}
a.btn{display:inline-block;margin-top:20px;padding:8px 20px;background:#337ab7;color:#fff;text-decoration:none;border-radius:4px}
.small{font-size:13px;color:#555;margin-top:12px}
</style>
</head>
<body>
<div class="box">
    <h2>Link Preview</h2>
    <div class="url"><b>Short:</b><br><?=SITE_URL?>/<?=$code?></div>
    <div class="url"><b>Destination:</b><br><?=htmlspecialchars($d['url'])?></div>
    <div class="small">
        Clicks: <?=number_format($d['clicks'])?><br>
        Created: <?=$d['created_at']?>
    </div>
    <a class="btn" href="<?=htmlspecialchars($d['url'])?>" rel="nofollow noopener">
        Go to destination →
    </a>
</div>
</body>
</html>
<?php
exit;
}

/* ================= API ================= */
if ($rawPath === 'api') {
    header('Content-Type: application/json');

    $url = trim($_GET['url'] ?? '');
    $custom = clean($_GET['custom'] ?? '');

    if(!$url){ echo json_encode(['error'=>'missing url']); exit; }
    if(!preg_match('#^https?://#',$url)) $url='https://'.$url;
    if(!filter_var($url,FILTER_VALIDATE_URL)){
        echo json_encode(['error'=>'invalid url']); exit;
    }

    if($custom){
        $c=$db->prepare("SELECT 1 FROM links WHERE code=?");
        $c->bindValue(1,$custom);
        if($c->execute()->fetchArray()){
            echo json_encode(['error'=>'custom taken']); exit;
        }
        $code=$custom;
    } else {
        do{
            $code=genCode();
            $c=$db->prepare("SELECT 1 FROM links WHERE code=?");
            $c->bindValue(1,$code);
        }while($c->execute()->fetchArray());
    }

    $i=$db->prepare("INSERT INTO links(code,url)VALUES(?,?)");
    $i->bindValue(1,$code);
    $i->bindValue(2,$url);
    $i->execute();

    echo json_encode(['short'=>SITE_URL.'/'.$code,'code'=>$code]);
    exit;
}

/* ================= REDIRECT ================= */
if ($rawPath!=='' && $rawPath!=='index.php') {
    $r=$db->prepare("SELECT url FROM links WHERE code=?");
    $r->bindValue(1,$rawPath);
    $d=$r->execute()->fetchArray(SQLITE3_ASSOC);
    if($d){
        $db->exec("UPDATE links SET clicks=clicks+1 WHERE code='$rawPath'");
        header("Location: ".$d['url'], true, isBot()?302:301);
        exit;
    }
}

/* ================= FORM ================= */
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $url=trim($_POST['url']??'');
    $custom=clean($_POST['custom']??'');

    if(!preg_match('#^https?://#',$url)) $url='https://'.$url;
    if(!filter_var($url,FILTER_VALIDATE_URL)){
        $error='Invalid URL';
    } else {
        if($custom){
            $c=$db->prepare("SELECT 1 FROM links WHERE code=?");
            $c->bindValue(1,$custom);
            if($c->execute()->fetchArray()) $error='Custom taken';
            $code=$custom;
        } else {
            do{
                $code=genCode();
                $c=$db->prepare("SELECT 1 FROM links WHERE code=?");
                $c->bindValue(1,$code);
            }while($c->execute()->fetchArray());
        }

        if(!$error){
            $i=$db->prepare("INSERT INTO links(code,url)VALUES(?,?)");
            $i->bindValue(1,$code);
            $i->bindValue(2,$url);
            $i->execute();
            header("Location: ?created=".$code);
            exit;
        }
    }
}

$short='';
if(isset($_GET['created'])) $short=SITE_URL.'/'.clean($_GET['created']);

$total=$db->querySingle("SELECT COUNT(*) FROM links");
$clicks=$db->querySingle("SELECT SUM(clicks) FROM links")?:0;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?=SITE_NAME?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;font-family:Arial;background:#fff;text-align:center}
.main{margin-top:80px}
.logo img{max-width:300px}
input{width:420px;padding:6px;font-size:14px;margin-top:10px}
button{margin-top:10px;padding:5px 16px}
.result{margin-top:15px;font-weight:bold}
.error{color:#c00;margin-top:10px}
.stats{margin-top:25px;font-size:13px}
</style>
</head>
<body>

<div class="main">
    <div class="logo"><img src="<?=LOGO?>"></div>

    <form method="post">
        <input name="url" placeholder="https://example.com" required>
        <input name="custom" placeholder="custom (optional)">
        <button>Shorten!</button>
    </form>

    <?php if($error): ?><div class="error"><?=$error?></div><?php endif; ?>
    <?php if($short): ?><div class="result"><a href="<?=$short?>"><?=$short?></a></div><?php endif; ?>

    <div class="stats">
        Shortening <b><?=number_format($total)?></b> URLs<br>
        That have been accessed <b><?=number_format($clicks)?></b> times
    </div>
</div>

</body>
</html>
