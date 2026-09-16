<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../app/bootstrap.php';
if(!str_ends_with(config('db')['database'],'_test'))throw new RuntimeException('Use a dedicated database ending in _test.');
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(isset($in['sql']))echo json_encode(rows($in['sql'],$in['params']??[]),JSON_THROW_ON_ERROR);
elseif(isset($in['decrypt_job']))echo unseal((string)scalar('SELECT payload FROM mail_jobs WHERE id=?',[$in['decrypt_job']]));
