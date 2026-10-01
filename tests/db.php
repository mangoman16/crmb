<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/database-name.php';
require __DIR__.'/../app/bootstrap.php';
// The same rule as the harness, from the same file: a name that merely ends in
// _test can still carry a second dbname into the connection string.
if(!test_database_name_allowed((string)(config('db')['database']??'')))throw new RuntimeException('Use a dedicated database whose name is letters, digits and underscores ending in _test.');
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(isset($in['sql']))echo json_encode(rows($in['sql'],$in['params']??[]),JSON_THROW_ON_ERROR);
elseif(isset($in['decrypt_job']))echo unseal((string)scalar('SELECT payload FROM mail_jobs WHERE id=?',[$in['decrypt_job']]));
