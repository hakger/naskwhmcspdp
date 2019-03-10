<?php

namespace WHMCS\Module\Registrar\Nask;

use AfriCC\EPP\ClientInterface;
use AfriCC\EPP\HTTPClient;
use AfriCC\EPP\Extension\NASK\Create\Contact as ContactCreateFrame;
use AfriCC\EPP\Extension\NASK\Info\Contact as ContactInfoFrame;
use AfriCC\EPP\Frame\Command\Check\Contact as ContactCheckFrame;
use AfriCC\EPP\Extension\NASK\Update\Contact as ContactUpdateFrame;
use AfriCC\EPP\Frame\Command\Delete\Contact as ContactDeleteFrame;
use AfriCC\EPP\Frame\Command\Check\Domain as DomainCheckFrame;
use AfriCC\EPP\Extension\NASK\Create\Domain as DomainCreateFrame;
use AfriCC\EPP\Frame\Command\Info\Domain as DomainInfoFrame;
use AfriCC\EPP\Extension\NASK\Update\Domain as DomainUpdateFrame;
use AfriCC\EPP\Extension\NASK\Transfer\Domain as DomainTransferFrame;
use AfriCC\EPP\Extension\NASK\Renew\Domain as DomainRenewFrame;
use AfriCC\EPP\Frame\Command\Delete\Domain as DomainDeleteFrame;

/**
 * NASK Registrar Module Simple EPP Client.
 *
 * A simple EPP Client for communicating with an external API endpoint.
 */
class ApiClient
{

    //nask consts
    const MAX_CHECK = 20;
    const DOMAIN_AVAIL = true;
    const DOMAIN_NOT_AVAIL = false;
    const DOMAIN_UNHANDLED_TLD = 3;
    const DOMAIN_ERROR = 4;

    /**
     * EPP Client
     * @var ClientInterface
     */
    protected $client;

    public function __construct(string $eppHost, string $eppUser, string $eppPass, string $caCert, string $certificate, string $privateKey)
    {
        \AfriCC\EPP\Extension\NASK\ObjectSpec::overwriteParent();
        $config = [
            'debug' => true,
            'host' => $eppHost,
            'username' => $eppUser,
            'password' => $eppPass,
            'services' => \AfriCC\EPP\Extension\NASK\ObjectSpec::$services,
            'serviceExtensions' => \AfriCC\EPP\Extension\NASK\ObjectSpec::$serviceExtensions,
            //'ssl' => true,
            'ca_cert' => $caCert,
            'local_cert' => $certificate,
            'pk_cert' => $privateKey,
        ];
        $this->client=new HTTPClient($config);
        logModuleCall(
            'NASK',
            'ApiClient::__construct',
            $config,
            [],
            [],
            [$eppUser,$eppPass]
            );
    }

    /**
     *
     * @param string $contactId
     * @return \AfriCC\EPP\Frame\Command\Check\Contact
     */
    private function getContactCheckFrame($contactId)
    {
        $frame = new ContactCheckFrame();
        $frame->addId($contactId);
        return $frame;
    }

    /**
     * Get contact availability
     *
     * @param string $contactId
     * @return boolean contact availability
     */
    public function isContactAvailable(string $contactId) {
        $frame = $this->getContactCheckFrame($contactId);
        $response = $this->client->request($frame);

        $avail =  !$response->success() ? false : filter_var($response->data()['chkData']['cd']['@id']['avail'], FILTER_VALIDATE_BOOLEAN);

        logModuleCall(
            'NASK',
            __METHOD__,
            $frame->__toString(),
            $response->__toString(),
            ['avail' => $avail, 'reason' => $response->data()['chkData']['cd']],
            ['password']);

        return $avail;
    }

    /**
     *
     * @param array $data
     * @return \AfriCC\EPP\Extension\NASK\Create\Contact
     */
    private function getContactCreateFrame(array $data) {
        $frame = new ContactCreateFrame();
        $frame->skipInt(true);
        $frame->setId($data['id']);
        $frame->setName($data['name']);
        if(!empty($data['company'])){
            $frame->setOrganization($data['company']);
            $frame->setIndividual(false);
        }
        foreach ($data['street'] as $street){
            if(!empty($street)) {
                $frame->addStreet($street);
            }
        }
        $frame->setCity($data['city']);
        if(!empty($data['province'])){
            $frame->setProvince($data['province']);
        }
        $frame->setPostalCode($data['postcode']);
        $frame->setCountryCode($data['countrycode']);
        $frame->setVoice($data['fullphonenumber']);
        $frame->setEmail($data['email']);
        $frame->setAuthInfo();
        return $frame;
    }

    public function createContact($contactId, $params){
        $copy_keys = ['city','province', 'postcode', 'countrycode', 'fullphonenumber', 'email'];
        $data_base = [
            'id' => $contactId,
            'name' => $params['fullname'],
            'company' => $params['companyname'],
            'street' => [
                $params['address1'],
                $params['address2']
            ],
        ];
        $data = array_merge($data_base, array_intersect_key($params, array_flip($copy_keys)));
        $frame = $this->getContactCreateFrame($data);
        $response = $this->client->request($frame);
        logModuleCall(
            'NASK',
            __METHOD__,
            $frame->__toString(),
            $response->__toString(),
            $response->data(),
            ['password']);
        if(!$response->success()){
            throw new \Exception($response->message(), $response->code());
        }
        return true;
    }

    private function getDomainCreateFrame($domain, $registrant, $period, $ns){
        $frame = new DomainCreateFrame();
        $frame->setDomain(\idn_to_ascii($domain));
        $frame->setRegistrant($registrant);
        $frame->setPeriod($period);
        foreach ($ns as $nameserver) {
            $frame->addNs(\idn_to_ascii($nameserver));
        }
        $frame->setAuthInfo();
        return $frame;
    }

    public function registerDomain($domain, $registrant, $period, $ns){
        $reg_period = $period.'y';
        $nameservers = array_filter($ns);
        $frame = $this->getDomainCreateFrame($domain, $registrant, $reg_period, $nameservers);
        $response = $this->client->request($frame);
        logModuleCall(
            'NASK',
            __METHOD__,
            $frame->__toString(),
            $response->__toString(),
            $response->data(),
            ['password']);
        if(!$response->success()){
            throw new \Exception($response->message(), $response->code());
        }
        return true;
    }

    private function getCheckFrame(array $domains){
        $frame = new DomainCheckFrame();
        foreach ($domains as $domain){
            $frame->addDomain(\idn_to_ascii($domain));
        }
        return $frame;
    }

    public function checkDomainsAvailability($sld, array $tlds){
        $results=[];
        $requests=[];
        $sld_len = mb_strlen($sld);
        foreach ($tlds as $tld) {
            if(mb_substr($tld, 0, 1) !== '.'){
                //add dot
                $tld = '.'.$tld;
            }
            if(mb_substr($tld, -3)!=='.pl'){
                //we're not handling non '.pl' tlds
                $results[$sld.$tld] = [
                    'tld' => $tld,
                    'avail' => ApiClient::DOMAIN_UNHANDLED_TLD,
                ];
                continue;
            }
            $requests[]=$sld.$tld;
        }
        $chunked_requests = array_chunk($requests, ApiClient::MAX_CHECK);
        //checks
        foreach ($chunked_requests as $domains) {
            // reach max checks. need to send req now
            $frame = $this->getCheckFrame($domains);
            $response = $this->client->request($frame);
            logModuleCall(
                'NASK',
                __METHOD__,
                $frame,
                $response,
                $response->data(),
                [
                    'password'
                ]
            );
            if (! $response->success()) {
                // we had problem
                foreach ($domains as $req) {
                    $results[$req] = [
                        'tld' => mb_substr($req, $sld_len),
                        'avail' => ApiClient::DOMAIN_ERROR,
                        'error' => $response->message(),
                        'code' => $response->code()
                    ];
                }
            } else {
                $base = $response->data()['chkData']['cd'];
                $data = [];
                if (isset($base['name'])) {
                    $data[] = $base;
                } else {
                    $data = $base;
                }
                foreach ($data as $av_data) {
                    $results[$av_data['name']] = [
                        'tld' => mb_substr($av_data['name'], $sld_len),
                        'avail' => $av_data['@name']['avail'] || ($av_data['reason'] == 9072),
                        'code' => $av_data['reason'] ?? 0
                    ];
                }
            }
        }
        return $results;
    }

    protected $results = array();

    /**
     * Make external API call to registrar API.
     *
     * @param string $action
     * @param array $postfields
     *
     * @throws \Exception Connection error
     * @throws \Exception Bad API response
     *
     * @return array
     */
    public function call($action, $postfields)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_URL . $action);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('Connection Error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
        }
        curl_close($ch);

        $this->results = $this->processResponse($response);

        logModuleCall(
            'Registrarmodule',
            $action,
            $postfields,
            $response,
            $this->results,
            array(
                $postfields['username'], // Mask username & password in request/response data
                $postfields['password'],
            )
        );

        if ($this->results === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Bad response received from API');
        }

        return $this->results;
    }

    /**
     * Process API response.
     *
     * @param string $response
     *
     * @return array
     */
    public function processResponse($response)
    {
        return json_decode($response, true);
    }

    /**
     * Get from response results.
     *
     * @param string $key
     *
     * @return string
     */
    public function getFromResponse($key)
    {
        return isset($this->results[$key]) ? $this->results[$key] : '';
    }
}
