<?php

require './vendor/autoload.php';

$sharedConfig = [
    'profile' => 'default',
    'region' => 'us-east-1',
    'version' => 'latest'
];

$sdk = new Aws\Sdk($sharedConfig);
$r53 = $sdk->createRoute53();

$managedZone = "somedomain.com"; // TLD
$subdomain = "foobar"; // Subdomain (Optional)
$fullDomain = empty($subdomain)
    ? $managedZone
    : sprintf('%s.%s', $subdomain, $managedZone);

$domainARecord = getPublicIpAddressFor($fullDomain);
$myPublicIp = myPublicIpAddress();

if(($domainARecord and $myPublicIp) and ($domainARecord != $myPublicIp)) {
    $hostedZone = getHostedZone($r53, $managedZone);

    if(!empty($hostedZone)) {
        $zoneIdResponse = $hostedZone['Id'];
        $zoneId = explode('/', $zoneIdResponse)[2] ?? null;
        if($zoneId) {
            $success = updateRoute53ARecord($r53, $fullDomain, $myPublicIp, $zoneId);
            if($success) {
                echo "Yeah!\n";
            } else {
                echo "Oops!\n";
            }
        }
    }
}

function getHostedZone($r53, $managedZone) {
    $out = $r53->listHostedZones();
    return array_values(array_filter($out['HostedZones'], function($zone) use ($managedZone) {
            return $zone['Name'] == $managedZone . '.';
        }))[0] ?? [];
}

function myPublicIpAddress() {
    $ipAddress = json_decode(
        file_get_contents('https://api.ipify.org?format=json')
    );
    return $ipAddress->ip;
}

function getPublicIpAddressFor($domain) {
    return filter_var(
        gethostbyname($domain), FILTER_VALIDATE_IP
    ) ?: false;
}

function updateRoute53ARecord($r53, $fullDomain, $myPublicIp, $zoneId) {
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
        var_dump($e->getMessage());
        return false;
    }
}