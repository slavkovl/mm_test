<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

//Good idea would be to have all this separated in different files, 
//but for demonstration purposes, I will keep all stuff in one place


//Set up a database connection.
$config = [
    'connection' => 'mysql',
    'name' => '',
    'username' => '',
    'password' => '',
    'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
];

try {
    $pdo = new PDO(
            $config['connection'] . ';dbname=' . $config['name'],
            $config['username'],
            $config['password'],
            $config['options']
    );
} catch (PDOException $e) {
    die($e->getMessage());
}




//Set up a API connection.

$client = new Client(['base_uri' => 'https://api.outbrain.com/amplify/v0.1/']);

$apiUsername = '';
$apiPassword = '';
$credentials = base64_encode("{$apiUsername}:{$apiPassword}");

$startdate = '2015-12-22';
$enddate = date('Y-m-d', strtotime($startdate . ' +1 day'));





//Getting Authorization Token
try {
    $authorizationResponse = $client->request('GET', 'login',
            ['headers' => [
                    'Authorization' => "BASIC {$credentials}"
                ]
            ]
    );

    $authorization = json_decode($authorizationResponse->getBody(), 1);
    $token = $authorization['OB-TOKEN-V1'];
} catch (Exception $e) {
    echo $e->getMessage();
}
$headers = ['OB-TOKEN-V1' => $token];





//Get all Marketers
try {
    $marketersResonse = $client->request('GET', 'marketers', ['headers' => $headers]);
    $marketers = json_decode($marketersResonse->getBody(), 1);

    
    //Get first Makrketer ID just for an example
    if (isset($marketers['marketers']) && count($marketers['marketers'])) {
        $firstMarketerId = $marketers['marketers'][0]['id'];
    }
} catch (Exception $e) {
    throw $e->getMessage();
}



//Marketer campaigns by a periodic breakdown
try {
    $limit = 10;
    $offset = 0;
    while (true) {
        $campaignsResponse = $client->request('GET', 'reports/marketers/id/campaigns/periodic', [
            'headers' => $headers,
            'query' => [
                'id' => $firstMarketerId,
                'start' => $startdate,
                'to' => $enddate,
                'breakdown' => 'daily',
                'limit' => $limit,
                'offset' => $offset * $limit
            ]
        ]);

        $campaigns = json_decode($campaignsResponse->getBody(), 1);

        //if no more results are set then break a loop
        if (!isset($campaigns['totalCampaigns']) || $campaigns['totalCampaigns'] == 0) {
            break;
        } else {
            
            
            try {
            
                //let's prepare a query and start a Transaction
                $sql = 'INSERT INTO campaign_metrics (campaign_id, date_id, metrics) VALUES(:var1, :var2, :var3) '
                                . 'ON DUPLICATE KEY UPDATE campaign_id = :var1, date_id = :var2, metrics = :var3';
                $query = $pdo->prepare($sql);
                $pdo->beginTransaction();



                //otherwise, loop thru given campaigns
                foreach ($campaigns['campaignResults'] as $campaign) {

                    //In this place, I am a bit confused, 
                    //because as long as we are retrieving data just for one day, 
                    //then there should be only one resulting item in a 'results' array. 
                    //But the example provided on the API website always returns 
                    //different days and out of asked date range.

                    foreach ($campaign['results'] as $result) {

                        //imagine we have a table:
                        //CREATE TABLE `campaign_metrics` (
                        //  `id` int(11) NOT NULL,
                        //  `campaign_id` varchar(34) NOT NULL,
                        //  `date_id` varchar(10) NOT NULL,
                        //  `metrics` json NOT NULL
                        //) ENGINE=InnoDB DEFAULT CHARSET=utf8;


                        $query->bindParam(':var1', $campaign['campaignId'], PDO::PARAM_STR); //campaign_id
                        $query->bindParam(':var2', $result['metadata']['id'], PDO::PARAM_STR); // date_id
                        $query->bindParam(':var3', json_encode($result['metrics']), PDO::PARAM_STR); //metrics
                        $query->execute();
                    }
                }

                $pdo->commit();
            
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollback();
                }
                throw $e;
            }
            
        }

        $offset++;
    }
} catch (RequestException $e) {
    throw $e->getMessage();
}
