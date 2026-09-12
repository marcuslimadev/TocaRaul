<?php
declare(strict_types=1);
require_once __DIR__.'/admin_settings.php';

function asaas_api(string $method, string $path, ?array $body=null): array {
    $key=runtime_setting('asaas_api_key','ASAAS_API_KEY');
    if ($key==='') throw new RuntimeException('Configure a chave Asaas sandbox no administrador.');
    // Deliberately sandbox-only until the complete financial flow is approved.
    $ch=curl_init('https://api-sandbox.asaas.com/v3'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>['access_token: '.$key,'Content-Type: application/json','User-Agent: TocaRaul-Sandbox/1.0'],
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE));
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if($raw===false) throw new RuntimeException('Asaas indisponível. Consulte o pedido antes de tentar novamente.');
    $data=json_decode($raw,true);
    if($status<200||$status>=300||!is_array($data)) {
        error_log('Asaas '.$method.' '.$path.' HTTP '.$status.' '.json_encode($data['errors']??[]));
        throw new RuntimeException('Asaas recusou a operação (HTTP '.$status.').');
    }
    return $data;
}

function asaas_ready(): bool { return runtime_setting('asaas_api_key','ASAAS_API_KEY')!==''; }

/** Sandbox-only: asks Asaas' own sandbox to mark a Pix charge as paid. This endpoint does not exist outside sandbox. */
function asaas_sandbox_confirm(string $externalId): array { return asaas_api('POST','/sandbox/payment/'.rawurlencode($externalId).'/confirm',[]); }

function asaas_create_pix(int $requestId,int $amount,string $description,string $name,string $document):array {
    if($amount<=0||!preg_match('/^(\d{11}|\d{14})$/',$document)) throw new InvalidArgumentException('Informe CPF/CNPJ válido do pagador.');
    $customer=asaas_api('POST','/customers',['name'=>$name,'cpfCnpj'=>$document,'notificationDisabled'=>true,'externalReference'=>'tocaraul_request_'.$requestId]);
    if(empty($customer['id'])) throw new RuntimeException('Asaas não retornou cliente.');
    $payment=asaas_api('POST','/payments',['customer'=>$customer['id'],'billingType'=>'PIX','value'=>$amount/100,
        'dueDate'=>(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
        'description'=>$description,'externalReference'=>'tocaraul_'.$requestId]);
    if(empty($payment['id'])) throw new RuntimeException('Asaas não retornou cobrança.');
    return $payment;
}

/** Re-fetch provider state, validate identity and amount, then update all local effects atomically. */
function asaas_reconcile(string $externalId):array {
    $d=settings_db();
    $q=$d->prepare("SELECT * FROM payments WHERE provider='asaas' AND externalId=? LIMIT 1");$q->execute([$externalId]);
    $local=$q->fetch();if(!$local) return ['ignored'=>true];
    $remote=asaas_api('GET','/payments/'.rawurlencode($externalId));
    if(($remote['id']??'')!==$externalId||($remote['externalReference']??'')!=='tocaraul_'.$local['requestId']||
        (int)round((float)($remote['value']??0)*100)!==(int)$local['amountCents']||($remote['billingType']??'')!=='PIX') {
        throw new RuntimeException('Cobrança divergente; conciliação recusada.');
    }
    $state=(string)($remote['status']??'');
    $d->beginTransaction();
    try {
        $q=$d->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$local['id']]);$pay=$q->fetch();
        $q=$d->prepare('SELECT * FROM songRequests WHERE id=? FOR UPDATE');$q->execute([$local['requestId']]);$req=$q->fetch();
        if(!$req||!$pay) throw new RuntimeException('Pedido inconsistente.');
        $q=$d->prepare('SELECT * FROM venues WHERE id=? FOR UPDATE');$q->execute([$req['venueId']]);$venue=$q->fetch();
        if(!$venue) throw new RuntimeException('Bar não encontrado.');
        if(in_array($state,['RECEIVED','CONFIRMED'],true)&&$pay['status']!=='CANCELLED') {
            $d->prepare("UPDATE payments SET status='APPROVED' WHERE id=?")->execute([$pay['id']]);
            if($req['status']==='AWAITING_PAYMENT') {
                $q=$d->prepare("SELECT COALESCE(MAX(queuePosition),0)+1 FROM songRequests WHERE venueId=? AND status IN ('QUEUED','PLAYING')");$q->execute([$req['venueId']]);
                $d->prepare("UPDATE songRequests SET status='QUEUED',queuePosition=? WHERE id=?")->execute([(int)$q->fetchColumn(),$req['id']]);
            }
            $gross=(int)$pay['amountCents'];$bar=(int)$req['barShareCents'];
            $q=$d->prepare("INSERT INTO financeLedger(venueId,requestId,paymentId,type,grossCents,barCents,platformCents,balanceStatus) VALUES(?,?,?,'SALE',?,?,?,?) ON DUPLICATE KEY UPDATE balanceStatus=IF(balanceStatus='AVAILABLE','AVAILABLE',VALUES(balanceStatus))");
            $q->execute([$req['venueId'],$req['id'],$pay['id'],$gross,$bar,$gross-$bar,$state==='RECEIVED'?'AVAILABLE':'PENDING']);
        } elseif($state==='REFUNDED') {
            $d->prepare("UPDATE payments SET status='CANCELLED' WHERE id=?")->execute([$pay['id']]);
            $d->prepare("UPDATE songRequests SET status='CANCELLED' WHERE id=? AND status IN ('AWAITING_PAYMENT','QUEUED')")->execute([$req['id']]);
            $d->prepare("UPDATE financeLedger SET balanceStatus='CANCELLED' WHERE paymentId=? AND type='SALE'")->execute([$pay['id']]);
        }
        $d->commit();
        invalidate_venue_state((int)$req['venueId']);
        return ['ok'=>true,'providerStatus'=>$state];
    }catch(Throwable $e){$d->rollBack();throw $e;}
}
