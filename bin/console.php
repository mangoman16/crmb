#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$command=$argv[1]??'help';
if($command==='key'){echo base64_encode(random_bytes(32)).PHP_EOL;exit;}
if($command==='help'){
    echo "Badminton CRM\n\nphp bin/console.php key\nphp bin/console.php migrate\nphp bin/console.php create-admin\nphp bin/console.php mail:work [limit]\nphp bin/console.php maintenance\nphp bin/console.php maintenance:on\nphp bin/console.php maintenance:off\nphp bin/console.php check\nphp bin/console.php version\n";exit;
}
try{
    require __DIR__.'/../app/bootstrap.php';
    if($command==='version'){echo trim(file_get_contents(ROOT.'/VERSION')).PHP_EOL;exit;}
    if($command==='maintenance:on'){
        if(file_put_contents(maintenance_file(),now().PHP_EOL)===false)throw new RuntimeException('Cannot create maintenance file.');
        echo "Maintenance enabled. New web requests and mail workers are paused. Let running requests finish before migrating.\n";exit;
    }
    if($command==='maintenance:off'){
        if(is_file(maintenance_file())&&!unlink(maintenance_file()))throw new RuntimeException('Cannot remove maintenance file.');
        echo "Maintenance disabled.\n";exit;
    }
    if($command==='check'){
        $result=['version'=>trim(file_get_contents(ROOT.'/VERSION')),'php'=>PHP_VERSION,'maintenance'=>is_file(maintenance_file()),'schema'=>scalar('SELECT MAX(version) FROM schema_migrations')];
        foreach(['accounts','students','contacts','field_definitions','field_values','absences','charges','payments','threads','messages','news','mail_jobs'] as $table)$result['rows'][$table]=(int)scalar('SELECT COUNT(*) FROM '.$table);
        $result['totals_cents']=['charges'=>(int)scalar('SELECT COALESCE(SUM(amount_cents),0) FROM charges WHERE cancelled=0'),'confirmed_payments'=>(int)scalar('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE voided=0 AND confirmed_at IS NOT NULL')];
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit;
    }
    if($command==='migrate'){
        run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if((int)scalar("SELECT GET_LOCK('badminton_crm_migrate',0)")!==1)throw new RuntimeException('Another migration is running.');
        try{
            foreach(glob(ROOT.'/database/migrations/*.sql') as $file){
                $version=basename($file);$hash=hash_file('sha256',$file);$old=one('SELECT * FROM schema_migrations WHERE version=?',[$version]);
                if($old){if(!hash_equals($old['checksum'],$hash))throw new RuntimeException('Applied migration changed: '.$version);continue;}
                $sql=file_get_contents($file);
                foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement)if(trim($statement)!=='')db()->exec(trim($statement));
                run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)',[$version,$hash,now()]);
                echo 'Applied '.$version.PHP_EOL;
            }
            require ROOT.'/database/defaults.php';
        }finally{run("SELECT RELEASE_LOCK('badminton_crm_migrate')");}
        echo "Database is up to date.\n";exit;
    }
    if($command==='create-admin'){
        if((int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'")>0)throw new RuntimeException('An administrator already exists. Invite additional accounts in the app.');
        function ask(string $label,bool $secret=false):string{
            static $tty=null;$tty??=stream_isatty(STDIN);fwrite(STDOUT,$label.': ');
            if($secret&&$tty)shell_exec('stty -echo');
            try{$value=trim((string)fgets(STDIN));}finally{if($secret&&$tty){shell_exec('stty echo');fwrite(STDOUT,PHP_EOL);}}
            return $value;
        }
        $name=ask('Name');$email=email_value(ask('Email'));$password=strong_password(ask('Password (12+ characters)',true));
        if($password!==ask('Repeat password',true))throw new RuntimeException('Passwords do not match.');
        if($name===''||mb_strlen($name)>160)throw new RuntimeException('Invalid name.');
        // The first administrator is provisioned by the server owner; no web signup exists.
        run("INSERT INTO accounts (name,email,password_hash,role,state,verified_at,created_at) VALUES (?,?,?,'admin','active',?,?)",[$name,$email,password_hash($password,PASSWORD_DEFAULT),now(),now()]);
        echo "Administrator created. Sign in to configure SMTP and the privacy notice.\n";exit;
    }
    if($command==='mail:work'){$result=process_mail((int)($argv[2]??25));echo json_encode($result).PHP_EOL;exit($result['failed']?1:0);}
    if($command==='maintenance'){
        run('DELETE FROM auth_tokens WHERE expires_at<?',[now()]);
        run('DELETE FROM rate_limits WHERE window_start<?',[time()-86400]);
        run('DELETE FROM form_requests WHERE created_at<?',[gmdate('Y-m-d H:i:s',time()-604800)]);
        echo "Expired tokens and temporary request records removed.\n";exit;
    }
    throw new RuntimeException('Unknown command. Run: php bin/console.php help');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
