<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('app');
$logger->pushHandler(
    new RotatingFileHandler(__DIR__ . '/logs/app.log', 5, Logger::DEBUG)
);

$config = require __DIR__ . '/config.php';

$sharedConfig = [
    'profile' => $config['aws']['profile'],
    'region' => $config['aws']['region'],
    'version' => $config['aws']['version']
];

$sdk = new Aws\Sdk($sharedConfig);
$r53 = $sdk->createRoute53();

$logger->info('running');
foreach ($config['domains'] as $domain) {

    $managedZone = $domain['name'];

    if ($domain['validateTld']) {
        processZone($managedZone);
    }

    foreach ($domain['subdomains'] as $subdomain) {
        $fullDomain = empty($subdomain)
            ? $managedZone
            : sprintf('%s.%s', $subdomain, $managedZone);
        processZone($fullDomain, $managedZone);
    }
}

function processZone($fullDomain, $managedZone = null)
{
    global $logger;

    $managedZone = $managedZone ?: $fullDomain;

    $domainARecord = getPublicIpAddressFor($fullDomain);
    $myPublicIp = myPublicIpAddress();

    if ($domainARecord != $myPublicIp) {
        $logger->info('@processZone: DNS needs to be updated for this A record', [
            'fullDomain' => $fullDomain,
            'domainARecord' => $domainARecord,
            'myPublicIp' => $myPublicIp
        ]);
        $hostedZone = getHostedZone($managedZone);

        if (!empty($hostedZone)) {
            $zoneIdResponse = $hostedZone['Id'];
            $zoneId = explode('/', $zoneIdResponse)[2] ?? null;
            if ($zoneId) {
                $success = updateRoute53ARecord($fullDomain, $myPublicIp, $zoneId);
                if ($success) {
                    $logger->info('@processZone: Successfully updated Route53 A record');
                } else {
                    $logger->error('@processZone: Could not update Route53 A record');
                }
            }
        }
    }
}

function getHostedZone($managedZone)
{
    global $r53;

    $out = $r53->listHostedZones();
    return array_values(array_filter($out['HostedZones'], function ($zone) use ($managedZone) {
        return $zone['Name'] == $managedZone . '.';
    }))[0] ?? [];
}

function myPublicIpAddress()
{
    $ipAddress = json_decode(
        file_get_contents('https://api.ipify.org?format=json')
    );
    return $ipAddress->ip;
}

function getPublicIpAddressFor($domain)
{
    return filter_var(
        gethostbyname($domain),
        FILTER_VALIDATE_IP
    ) ?: false;
}

function updateRoute53ARecord($fullDomain, $myPublicIp, $zoneId): bool
{
    global $r53, $logger;

    date_default_timezone_set('America/New_York');
    $date = date('Y-m-d H:i:s');

    try {
        $r53->changeResourceRecordSets([
            "Comment" => "RYO DyDNS on $date",
            "ChangeBatch" => [
                "Changes" => [
                    [
                        "Action" => "UPSERT",
                        "ResourceRecordSet" => [
                            "Name" => $fullDomain,
                            "Type" => "A",
                            "TTL" => 60,
                            "ResourceRecords" => [
                                [
                                    "Value" => $myPublicIp
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'HostedZoneId' => $zoneId
        ]);
        return true;
    } catch (\Exception $e) {
        $logger->error('@updateRoute53ARecord: ' . $e->getMessage());
        return false;
    }
}
