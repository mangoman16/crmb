#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$command=$argv[1]??'help';
if($command==='key'){echo base64_encode(random_bytes(32)).PHP_EOL;exit;}
// Answered before the configuration is loaded, because the point of asking is
// usually to identify a release directory that has not been configured yet.
if($command==='version'){echo trim((string)file_get_contents(__DIR__.'/../VERSION')).PHP_EOL;exit;}
if($command==='help'){
    echo "Badminton CRM\n\n"
        ."php bin/console.php update            One-step upgrade: maintenance on, migrate, maintenance off\n"
        ."php bin/console.php key\n"
        ."php bin/console.php migrate\n"
        ."php bin/console.php create-admin\n"
        ."php bin/console.php mail:work [limit]\n"
        ."php bin/console.php billing:run [YYYY-MM]  Create the monthly charges (default: this month)\n"
        ."php bin/console.php billing:plan [YYYY-MM] Show what billing:run would do, changing nothing\n"
        ."php bin/console.php maintenance       Prune expired tokens and temporary records\n"
        ."php bin/console.php maintenance:on\n"
        ."php bin/console.php maintenance:off\n"
        ."php bin/console.php check\n"
        ."php bin/console.php version\n";exit;
}
try{
    require __DIR__.'/../app/bootstrap.php';
    if($command==='maintenance:on'){
        if(file_put_contents(maintenance_file(),now().PHP_EOL)===false)throw new RuntimeException('Cannot create maintenance file.');
        echo "Maintenance enabled. New web requests and mail workers are paused. Let running requests finish before migrating.\n";exit;
    }
    if($command==='maintenance:off'){
        if(is_file(maintenance_file())&&!unlink(maintenance_file()))throw new RuntimeException('Cannot remove maintenance file.');
        echo "Maintenance disabled.\n";exit;
    }
    if($command==='check'){
        $schema=null;
        try{$schema=scalar('SELECT MAX(version) FROM schema_migrations');}catch(PDOException){$schema='not migrated';}
        $result=['version'=>trim(file_get_contents(ROOT.'/VERSION')),'php'=>PHP_VERSION,'maintenance'=>is_file(maintenance_file()),'schema'=>$schema];
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
                if($old){
                    if(!hash_equals($old['checksum'],$hash))
                        throw new RuntimeException('Migration '.$version.' has changed since it was applied. Never edit an applied migration; add a new one instead. To accept a deliberate change, update its checksum in schema_migrations.');
                    continue;
                }
                $statements=split_sql(file_get_contents($file));
                foreach($statements as $i=>$statement){
                    try{ db()->exec($statement); }
                    catch(Throwable $e){
                        // MySQL DDL is not transactional, so a failure here leaves the
                        // migration half applied and unrecorded. Say exactly which
                        // statement stopped, because the operator has to decide between
                        // finishing it by hand and restoring a backup.
                        throw new RuntimeException(
                            $version.' failed at statement '.($i+1).' of '.count($statements).":\n\n"
                            .substr(preg_replace('/\s+/',' ',$statement),0,300)."\n\n"
                            .$e->getMessage()."\n\n"
                            ."Statements 1 to ".$i." were applied and this migration is NOT recorded as done,\n"
                            ."so re-running would start it again from the beginning. Restore your backup, or\n"
                            ."finish this migration by hand and add the row to schema_migrations yourself.");
                    }
                }
                run('INSERT INTO schema_migrations (version,checksum,applied_at) VALUES (?,?,?)',[$version,$hash,now()]);
                echo 'Applied '.$version.' ('.count($statements).' statements)'.PHP_EOL;
            }
            require ROOT.'/database/defaults.php';
        }finally{run("SELECT RELEASE_LOCK('badminton_crm_migrate')");}
        echo "Database is up to date.\n";exit;
    }
    if($command==='update'){
        // Deliberately sequential and loud: each step prints before it runs, so a
        // failure says exactly how far the upgrade got.
        $already=is_file(maintenance_file());
        echo $already?"Maintenance mode was already on; leaving it on at the end.\n":"1/4 Switching maintenance mode on\n";
        if(!$already && file_put_contents(maintenance_file(),now().PHP_EOL)===false)
            throw new RuntimeException('Cannot write '.maintenance_file().'. Check that the directory is writable.');
        try{
            echo "2/4 Applying migrations\n";
            $before=[]; $after=[];
            foreach(['accounts','students','charges','payments','messages'] as $table)
                $before[$table]=(int)scalar('SELECT COUNT(*) FROM '.$table);
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' migrate',$code);
            if($code!==0) throw new RuntimeException('Migration failed; maintenance mode stays on so nobody sees a half-migrated portal.');
            echo "3/4 Verifying record counts\n";
            foreach($before as $table=>$n) {
                $after[$table]=(int)scalar('SELECT COUNT(*) FROM '.$table);
                printf("    %-10s %d -> %d%s\n",$table,$n,$after[$table],$after[$table]<$n?'  *** ROWS LOST ***':'');
            }
            $lost=array_filter($after,fn($n,$t)=>$n<$before[$t],ARRAY_FILTER_USE_BOTH);
            if($lost) throw new RuntimeException('Row counts dropped; maintenance mode stays on. Restore a backup before continuing.');
        }catch(Throwable $e){
            fwrite(STDERR,"\nUpgrade stopped: ".$e->getMessage()."\n");
            fwrite(STDERR,"The portal stays in maintenance mode. Fix the cause, then run this again.\n");
            exit(1);
        }
        if($already) { echo "4/4 Done. Maintenance mode left on, as it was before.\n"; exit; }
        echo "4/4 Switching maintenance mode off\n";
        if(is_file(maintenance_file())&&!unlink(maintenance_file()))throw new RuntimeException('Cannot remove '.maintenance_file().'; the portal is still closed.');
        echo "Update complete.\n";exit;
    }
    if($command==='create-admin'){
        // A second administrator can be created deliberately with --force; without
        // it the guard stays, so a stray run cannot quietly add one.
        if((int)scalar("SELECT COUNT(*) FROM accounts WHERE role='admin'")>0 && ($argv[2]??'')!=='--force')
            throw new RuntimeException("An administrator already exists. Invite further accounts in the app, or pass --force to create another from the command line.");
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
    if($command==='billing:plan'){
        $period=billing_valid_period($argv[2]??billing_current_period());
        $plan=billing_plan($period);
        $create=array_values(array_filter($plan,fn($r)=>$r['skip']===null));
        printf("%s: %d to create, %d skipped\n\n",$period,count($create),count($plan)-count($create));
        foreach($plan as $r) printf("  %-28s %10s  %s\n",mb_substr($r['name'],0,28),$r['skip']?'-':money((int)$r['amount']),$r['skip']??'will be created');
        printf("\ntotal: %s\n",money(array_sum(array_column($create,'amount'))));
        exit;
    }
    if($command==='billing:run'){
        // Safe to run from cron on the 1st: the unique billing_key means a second
        // run for the same month creates nothing.
        $result=billing_run(billing_valid_period($argv[2]??billing_current_period()));
        echo json_encode($result).PHP_EOL;exit;
    }
    if($command==='mail:work'){$result=process_mail((int)($argv[2]??25),(float)($argv[3]??0));echo json_encode($result).PHP_EOL;exit($result['failed']?1:0);}
    if($command==='maintenance'){
        run('DELETE FROM auth_tokens WHERE expires_at<?',[now()]);
        run('DELETE FROM rate_limits WHERE window_start<?',[time()-86400]);
        run('DELETE FROM form_requests WHERE created_at<?',[gmdate('Y-m-d H:i:s',time()-604800)]);
        echo "Expired tokens and temporary request records removed.\n";exit;
    }
    throw new RuntimeException('Unknown command. Run: php bin/console.php help');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
