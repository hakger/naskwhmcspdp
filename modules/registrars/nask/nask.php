<?php
/**
 * WHMCS NASK Registrar Module
 *
 * If your module or third party API does not support a given function, you
 * should not define the function within your module. WHMCS recommends that
 * all registrar modules implement Register, Transfer, Renew, GetNameservers,
 * SaveNameservers, GetContactDetails & SaveContactDetails.
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/domain-registrars/
 *
 * @copyright Copyright (c) WHMCS Limited 2017
 * @license https://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Nask\ApiClient;

// Require any libraries needed for the module to function.
require_once __DIR__ . '/vendor/autoload.php';
//
// Also, perform any initialization required by the service's library.

/**
 * Define module related metadata
 *
 * Provide some module information including the display name and API Version to
 * determine the method of decoding the input values.
 *
 * @return array
 */
function nask_MetaData()
{
    return array(
        'DisplayName' => 'NASK Registrar Module for WHMCS',
        'APIVersion' => '1.1',
    );
}

/**
 * Define registrar configuration options.
 *
 * The values you return here define what configuration options
 * we store for the module. These values are made available to
 * each module function.
 *
 * You can store an unlimited number of configuration settings.
 * The following field types are supported:
 *  * Text
 *  * Password
 *  * Yes/No Checkboxes
 *  * Dropdown Menus
 *  * Radio Buttons
 *  * Text Areas
 *
 * @return array
 */
function nask_getConfigArray()
{
    return array(
        // Friendly display name for the module
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'NASK Registrar Module for WHMCS',
        ),
        'Host' => array(
            'Type' => 'text',
            'Size' => 255,
            'Description' => "Hostname with protocol to NASK server",
            'Default' => "https://registry.dns.pl/registry/epp"
        ),
        // a text field type allows for single line text input
        'Username' => array(
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'user',
            'Description' => 'NASK EPP User name',
        ),
        // a password field type allows for masked text input
        'Password' => array(
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'NASK User password',
        ),
        "Prefix" => array(
            "Type" => "text",
            "Size" => "16",
            "FriendlyName" => "Contact Id Prefix",
            "Description" => "Enter mandatory prefix provided by NASK, min 3 letters",
        ),
        "CACert" => array(
            "Type" => "text",
            "Size" => "255",
            "FriendlyName"=> "CA Chain",
            "Description" => "Path to NASK CA chain. If starts with '/' is interpreted as absolute.",
            "Default" => "nask_root_ca.pem",
        ),
        "Cert" => array(
            "Type" => "text",
            "Size" => "255",
            "FriendlyName" => "Client Certificate",
            "Description" => "Path to client certificate issued by NASK",
            "Default" => "certificate_prod.pem",
        ),
        "PrivateKey" => array(
            "Type" => "text",
            "Size" => "255",
            "FriendlyName" => "Private Key",
            "Description" => "Path to client certificate Private Key issued by NASK",
            "Default" => "key_prod.pem",
        ),
        // the yesno field type displays a single checkbox option
        'Debug' => array(
            'Type' => 'yesno',
            'Description' => 'Enable debugging output',
        ),
    );
}

/**
 * Register a domain.
 *
 * Attempt to register a domain with the domain registrar.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain registration order
 * * When a pending domain registration order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_RegisterDomain($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            $params['Username'], // Mask username & password in request/response data
            $params['Password'],
        )
        );

    $host = $params['Host'];
    $user = $params['Username'];
    $pass = $params['Password'];
    $ca = $params['CACert'];
    $cert = $params['Cert'];
    $key = $params['PrivateKey'];

    $client = new ApiClient($host, $user, $pass, $ca, $cert, $key);

    $fullName = $params["fullname"]; // First name and last name combined
    $companyName = $params["companyname"];
    $email = $params["email"];
    $address1 = $params["address1"];
    $address2 = $params["address2"];
    $city = $params["city"];
    $postcode = $params["postcode"]; // Postcode/Zip code
    $phoneNumberFormatted = $params["fullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    $data_hash = md5($fullName.$companyName.$email.$address1.$address2.$city.$postcode.$phoneNumberFormatted);
    $data_fullalpha = base_convert($data_hash, 16, 36);

    $contactId = $params['Prefix'].$params['userid'];

    $contactId = $contactId . substr($data_fullalpha, 0, 16-strlen($contactId));
    try {
        if($client->isContactAvailable($contactId)){
            // contact avail, need to create contact
            $client->createContact($contactId, $params);
        }
    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }

    // registration parameters
    $domain = $params['sld'].'.'.$params['tld'];
    $registrationPeriod = $params['regperiod'];
    /**
     * Nameservers.
     *
     * If purchased with web hosting, values will be taken from the
     * assigned web hosting server. Otherwise uses the values specified
     * during the order process.
     */
    $nameservers = [
        $params['ns1'],
        $params['ns2'],
        $params['ns3'],
        $params['ns4'],
        $params['ns5'],
    ];

    try {
        $client->registerDomain($domain, $contactId, $registrationPeriod, $nameservers);

        return array(
            'success' => true,
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Initiate domain transfer.
 *
 * Attempt to create a domain transfer request for a given domain.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain transfer order
 * * When a pending domain transfer order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_TransferDomain($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
                'username', // Mask username & password in request/response data
                'password',
            )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // registration parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];
    $eppCode = $params['eppcode'];

    /**
     * Nameservers.
     *
     * If purchased with web hosting, values will be taken from the
     * assigned web hosting server. Otherwise uses the values specified
     * during the order process.
     */
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    // registrant information
    $firstName = $params["firstname"];
    $lastName = $params["lastname"];
    $fullName = $params["fullname"]; // First name and last name combined
    $companyName = $params["companyname"];
    $email = $params["email"];
    $address1 = $params["address1"];
    $address2 = $params["address2"];
    $city = $params["city"];
    $state = $params["state"]; // eg. TX
    $stateFullName = $params["fullstate"]; // eg. Texas
    $postcode = $params["postcode"]; // Postcode/Zip code
    $countryCode = $params["countrycode"]; // eg. GB
    $countryName = $params["countryname"]; // eg. United Kingdom
    $phoneNumber = $params["phonenumber"]; // Phone number as the user provided it
    $phoneCountryCode = $params["phonecc"]; // Country code determined based on country
    $phoneNumberFormatted = $params["fullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    /**
     * Admin contact information.
     *
     * Defaults to the same as the client information. Can be configured
     * to use the web hosts details if the `Use Clients Details` option
     * is disabled in Setup > General Settings > Domains.
     */
    $adminFirstName = $params["adminfirstname"];
    $adminLastName = $params["adminlastname"];
    $adminCompanyName = $params["admincompanyname"];
    $adminEmail = $params["adminemail"];
    $adminAddress1 = $params["adminaddress1"];
    $adminAddress2 = $params["adminaddress2"];
    $adminCity = $params["admincity"];
    $adminState = $params["adminstate"]; // eg. TX
    $adminStateFull = $params["adminfullstate"]; // eg. Texas
    $adminPostcode = $params["adminpostcode"]; // Postcode/Zip code
    $adminCountry = $params["admincountry"]; // eg. GB
    $adminPhoneNumber = $params["adminphonenumber"]; // Phone number as the user provided it
    $adminPhoneNumberFormatted = $params["adminfullphonenumber"]; // Format: +CC.xxxxxxxxxxxx

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    /**
     * Premium domain parameters.
     *
     * Premium domains enabled informs you if the admin user has enabled
     * the selling of premium domain names. If this domain is a premium name,
     * `premiumCost` will contain the cost price retrieved at the time of
     * the order being placed. The premium order should only be processed
     * if the cost price now matches that previously fetched amount.
     */
    $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
    $premiumDomainsCost = $params['premiumCost'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'eppcode' => $eppCode,
        'nameservers' => array(
            'ns1' => $nameserver1,
            'ns2' => $nameserver2,
            'ns3' => $nameserver3,
            'ns4' => $nameserver4,
            'ns5' => $nameserver5,
        ),
        'years' => $registrationPeriod,
        'contacts' => array(
            'registrant' => array(
                'firstname' => $firstName,
                'lastname' => $lastName,
                'companyname' => $companyName,
                'email' => $email,
                'address1' => $address1,
                'address2' => $address2,
                'city' => $city,
                'state' => $state,
                'zipcode' => $postcode,
                'country' => $countryCode,
                'phonenumber' => $phoneNumberFormatted,
            ),
            'tech' => array(
                'firstname' => $adminFirstName,
                'lastname' => $adminLastName,
                'companyname' => $adminCompanyName,
                'email' => $adminEmail,
                'address1' => $adminAddress1,
                'address2' => $adminAddress2,
                'city' => $adminCity,
                'state' => $adminState,
                'zipcode' => $adminPostcode,
                'country' => $adminCountry,
                'phonenumber' => $adminPhoneNumberFormatted,
            ),
        ),
        'dnsmanagement' => $enableDnsManagement,
        'emailforwarding' => $enableEmailForwarding,
        'idprotection' => $enableIdProtection,
    );

    try {
        $api = new ApiClient();
        $api->call('Transfer', $postfields);

        return array(
            'success' => true,
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Renew a domain.
 *
 * Attempt to renew/extend a domain for a given number of years.
 *
 * This is triggered when the following events occur:
 * * Payment received for a domain renewal order
 * * When a pending domain renewal order is accepted
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_RenewDomain($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // registration parameters
    $sld = $params['sld'];
    $tld = $params['tld'];
    $registrationPeriod = $params['regperiod'];

    // domain addon purchase status
    $enableDnsManagement = (bool) $params['dnsmanagement'];
    $enableEmailForwarding = (bool) $params['emailforwarding'];
    $enableIdProtection = (bool) $params['idprotection'];

    /**
     * Premium domain parameters.
     *
     * Premium domains enabled informs you if the admin user has enabled
     * the selling of premium domain names. If this domain is a premium name,
     * `premiumCost` will contain the cost price retrieved at the time of
     * the order being placed. A premium renewal should only be processed
     * if the cost price now matches that previously fetched amount.
     */
    $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
    $premiumDomainsCost = $params['premiumCost'];

    // Build post data.
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'years' => $registrationPeriod,
        'dnsmanagement' => $enableDnsManagement,
        'emailforwarding' => $enableEmailForwarding,
        'idprotection' => $enableIdProtection,
    );

    try {
        $api = new ApiClient();
        $api->call('Renew', $postfields);

        return array(
            'success' => true,
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Fetch current nameservers.
 *
 * This function should return an array of nameservers for a given domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_GetNameservers($params)
{
    // user defined configuration values
    $host = $params['Host'];
    $user = $params['Username'];
    $pass = $params['Password'];
    $ca = $params['CACert'];
    $cert = $params['Cert'];
    $key = $params['PrivateKey'];

    // domain parameters
    $domain = $params['sld'].'.'.$params['tld'];

    try {
        $client = new ApiClient($host, $user, $pass, $ca, $cert, $key);
        $data = $client->getDomainInfo($domain);

        if(isset($data['error']) && $data['error']){
            return [
                'error' => "NASK/getNameservers: Error [{$data['code']}] - {$data['message']}",
            ];
        }

        if(!isset($data['ns']) || empty($data['ns'])){
            return [
                'error' => "NASK/getNameservers: Empty NS!",
            ];
        }

        $result = ['success' => true];

        foreach($data['ns'] as $i => $ns){
            $result['ns'.($i+1)] = $ns;
        }

        logModuleCall(
            'NASK',
            __FUNCTION__,
            $params,
            $data,
            $result,
            array(
                'username', // Mask username & password in request/response data
                'password',
            )
            );

        return $result;
    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Save nameserver changes.
 *
 * This function should submit a change of nameservers request to the
 * domain registrar.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_SaveNameservers($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // submitted nameserver values
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver1' => $nameserver1,
        'nameserver2' => $nameserver2,
        'nameserver3' => $nameserver3,
        'nameserver4' => $nameserver4,
        'nameserver5' => $nameserver5,
    );

    try {
        $api = new ApiClient();
        $api->call('SetNameservers', $postfields);

        return array(
            'success' => true,
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Get the current WHOIS Contact Information.
 *
 * Should return a multi-level array of the contacts and name/address
 * fields that be modified.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_GetContactDetails($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('GetWhoisInformation', $postfields);

        return array(
            'Registrant' => array(
                'First Name' => $api->getFromResponse('registrant.firstname'),
                'Last Name' => $api->getFromResponse('registrant.lastname'),
                'Company Name' => $api->getFromResponse('registrant.company'),
                'Email Address' => $api->getFromResponse('registrant.email'),
                'Address 1' => $api->getFromResponse('registrant.address1'),
                'Address 2' => $api->getFromResponse('registrant.address2'),
                'City' => $api->getFromResponse('registrant.city'),
                'State' => $api->getFromResponse('registrant.state'),
                'Postcode' => $api->getFromResponse('registrant.postcode'),
                'Country' => $api->getFromResponse('registrant.country'),
                'Phone Number' => $api->getFromResponse('registrant.phone'),
                'Fax Number' => $api->getFromResponse('registrant.fax'),
            ),
            'Technical' => array(
                'First Name' => $api->getFromResponse('tech.firstname'),
                'Last Name' => $api->getFromResponse('tech.lastname'),
                'Company Name' => $api->getFromResponse('tech.company'),
                'Email Address' => $api->getFromResponse('tech.email'),
                'Address 1' => $api->getFromResponse('tech.address1'),
                'Address 2' => $api->getFromResponse('tech.address2'),
                'City' => $api->getFromResponse('tech.city'),
                'State' => $api->getFromResponse('tech.state'),
                'Postcode' => $api->getFromResponse('tech.postcode'),
                'Country' => $api->getFromResponse('tech.country'),
                'Phone Number' => $api->getFromResponse('tech.phone'),
                'Fax Number' => $api->getFromResponse('tech.fax'),
            ),
            'Billing' => array(
                'First Name' => $api->getFromResponse('billing.firstname'),
                'Last Name' => $api->getFromResponse('billing.lastname'),
                'Company Name' => $api->getFromResponse('billing.company'),
                'Email Address' => $api->getFromResponse('billing.email'),
                'Address 1' => $api->getFromResponse('billing.address1'),
                'Address 2' => $api->getFromResponse('billing.address2'),
                'City' => $api->getFromResponse('billing.city'),
                'State' => $api->getFromResponse('billing.state'),
                'Postcode' => $api->getFromResponse('billing.postcode'),
                'Country' => $api->getFromResponse('billing.country'),
                'Phone Number' => $api->getFromResponse('billing.phone'),
                'Fax Number' => $api->getFromResponse('billing.fax'),
            ),
            'Admin' => array(
                'First Name' => $api->getFromResponse('admin.firstname'),
                'Last Name' => $api->getFromResponse('admin.lastname'),
                'Company Name' => $api->getFromResponse('admin.company'),
                'Email Address' => $api->getFromResponse('admin.email'),
                'Address 1' => $api->getFromResponse('admin.address1'),
                'Address 2' => $api->getFromResponse('admin.address2'),
                'City' => $api->getFromResponse('admin.city'),
                'State' => $api->getFromResponse('admin.state'),
                'Postcode' => $api->getFromResponse('admin.postcode'),
                'Country' => $api->getFromResponse('admin.country'),
                'Phone Number' => $api->getFromResponse('admin.phone'),
                'Fax Number' => $api->getFromResponse('admin.fax'),
            ),
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Update the WHOIS Contact Information for a given domain.
 *
 * Called when a change of WHOIS Information is requested within WHMCS.
 * Receives an array matching the format provided via the `GetContactDetails`
 * method with the values from the users input.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_SaveContactDetails($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // whois information
    $contactDetails = $params['contactdetails'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'contacts' => array(
            'registrant' => array(
                'firstname' => $contactDetails['Registrant']['First Name'],
                'lastname' => $contactDetails['Registrant']['Last Name'],
                'company' => $contactDetails['Registrant']['Company Name'],
                'email' => $contactDetails['Registrant']['Email Address'],
                // etc...
            ),
            'tech' => array(
                'firstname' => $contactDetails['Technical']['First Name'],
                'lastname' => $contactDetails['Technical']['Last Name'],
                'company' => $contactDetails['Technical']['Company Name'],
                'email' => $contactDetails['Technical']['Email Address'],
                // etc...
            ),
            'billing' => array(
                'firstname' => $contactDetails['Billing']['First Name'],
                'lastname' => $contactDetails['Billing']['Last Name'],
                'company' => $contactDetails['Billing']['Company Name'],
                'email' => $contactDetails['Billing']['Email Address'],
                // etc...
            ),
            'admin' => array(
                'firstname' => $contactDetails['Admin']['First Name'],
                'lastname' => $contactDetails['Admin']['Last Name'],
                'company' => $contactDetails['Admin']['Company Name'],
                'email' => $contactDetails['Admin']['Email Address'],
                // etc...
            ),
        ),
    );

    try {
        $api = new ApiClient();
        $api->call('UpdateWhoisInformation', $postfields);

        return array(
            'success' => true,
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Check Domain Availability.
 *
 * Determine if a domain or group of domains are available for
 * registration or transfer.
 *
 * @param array $params common module parameters
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @see \WHMCS\Domains\DomainLookup\SearchResult
 * @see \WHMCS\Domains\DomainLookup\ResultsList
 *
 * @throws Exception Upon domain availability check failure.
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results
 */
function nask_CheckAvailability($params)
{
    // user defined configuration values
    $host = $params['Host'];
    $user = $params['Username'];
    $pass = $params['Password'];
    $ca = $params['CACert'];
    $cert = $params['Cert'];
    $key = $params['PrivateKey'];

    $client = new ApiClient($host, $user, $pass, $ca, $cert, $key);

    // availability check parameters
    $tldsToInclude = $params['tldsToInclude'];

    if ($params['isIdnDomain']) {
        $label = empty($params['punyCodeSearchTerm']) ? strtolower($params['searchTerm']) : strtolower($params['punyCodeSearchTerm']);
    } else {
        $label = strtolower($params['searchTerm']);
    }

    try {

        $domains = $client->checkDomainsAvailability($label, $tldsToInclude);

        $results = new ResultsList();
        foreach ($domains as $domain) {

            // Instantiate a new domain search result object
            $searchResult = new SearchResult($label, $domain['tld']);

            $status = SearchResult::STATUS_UNKNOWN;

            switch ($domain['avail']) {
                case ApiClient::DOMAIN_AVAIL:
                    $status = SearchResult::STATUS_NOT_REGISTERED;
                    break;
                case ApiClient::DOMAIN_NOT_AVAIL:
                    $status = SearchResult::STATUS_REGISTERED;
                    break;
                case ApiClient::DOMAIN_UNHANDLED_TLD:
                    $status = SearchResult::STATUS_TLD_NOT_SUPPORTED;
                    break;
                default:
                    $status = SearchResult::STATUS_UNKNOWN;
                break;
            }
            $searchResult->setStatus($status);

            // Append to the search results list
            $results->append($searchResult);
        }

        return $results;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Domain Suggestion Settings.
 *
 * Defines the settings relating to domain suggestions (optional).
 * It follows the same convention as `getConfigArray`.
 *
 * @see https://developers.whmcs.com/domain-registrars/check-availability/
 *
 * @return array of Configuration Options
 */
function nask_DomainSuggestionOptions() {
    return array(
        'includeCCTlds' => array(
            'FriendlyName' => 'Include Country Level TLDs',
            'Type' => 'yesno',
            'Description' => 'Tick to enable',
        ),
    );
}

/**
 * Get Domain Suggestions.
 *
 * Provide domain suggestions based on the domain lookup term provided.
 *
 * @param array $params common module parameters
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @see \WHMCS\Domains\DomainLookup\SearchResult
 * @see \WHMCS\Domains\DomainLookup\ResultsList
 *
 * @throws Exception Upon domain suggestions check failure.
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results
 */
function nask_GetDomainSuggestions($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // availability check parameters
    $searchTerm = $params['searchTerm'];
    $punyCodeSearchTerm = $params['punyCodeSearchTerm'];
    $tldsToInclude = $params['tldsToInclude'];
    $isIdnDomain = (bool) $params['isIdnDomain'];
    $premiumEnabled = (bool) $params['premiumEnabled'];
    $suggestionSettings = $params['suggestionSettings'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'searchTerm' => $searchTerm,
        'tldsToSearch' => $tldsToInclude,
        'includePremiumDomains' => $premiumEnabled,
        'includeCCTlds' => $suggestionSettings['includeCCTlds'],
    );

    try {
        $api = new ApiClient();
        $api->call('GetSuggestions', $postfields);

        $results = new ResultsList();
        foreach ($api->getFromResponse('domains') as $domain) {

            // Instantiate a new domain search result object
            $searchResult = new SearchResult($domain['sld'], $domain['tld']);

            // All domain suggestions should be available to register
            $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);

            // Used to weight results by relevance
            $searchResult->setScore($domain['score']);

            // Return premium information if applicable
            if ($domain['isPremiumName']) {
                $searchResult->setPremiumDomain(true);
                $searchResult->setPremiumCostPricing(
                    array(
                        'register' => $domain['premiumRegistrationPrice'],
                        'renew' => $domain['premiumRenewPrice'],
                        'CurrencyCode' => 'USD',
                    )
                );
            }

            // Append to the search results list
            $results->append($searchResult);
        }

        return $results;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Get registrar lock status.
 *
 * Also known as Domain Lock or Transfer Lock status.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return string|array Lock status or error message
 */
function nask_GetRegistrarLock($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('GetLockStatus', $postfields);

        if ($api->getFromResponse('lockstatus') == 'locked') {
            return 'locked';
        } else {
            return 'unlocked';
        }

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Set registrar lock status.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_SaveRegistrarLock($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // lock status
    $lockStatus = $params['lockenabled'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'registrarlock' => ($lockStatus == 'locked') ? 1 : 0,
    );

    try {
        $api = new ApiClient();
        $api->call('SetLockStatus', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Get DNS Records for DNS Host Record Management.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array DNS Host Records
 */
function nask_GetDNS($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('GetDNSHostRecords', $postfields);

        $hostRecords = array();
        foreach ($api->getFromResponse('records') as $record) {
            $hostRecords[] = array(
                "hostname" => $record['name'], // eg. www
                "type" => $record['type'], // eg. A
                "address" => $record['address'], // eg. 10.0.0.1
                "priority" => $record['mxpref'], // eg. 10 (N/A for non-MX records)
            );
        }
        return $hostRecords;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Update DNS Host Records.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_SaveDNS($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // dns record parameters
    $dnsrecords = $params['dnsrecords'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'records' => $dnsrecords,
    );

    try {
        $api = new ApiClient();
        $api->call('GetDNSHostRecords', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Enable/Disable ID Protection.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_IDProtectToggle($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // id protection parameter
    $protectEnable = (bool) $params['protectenable'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();

        if ($protectEnable) {
            $api->call('EnableIDProtection', $postfields);
        } else {
            $api->call('DisableIDProtection', $postfields);
        }

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Request EEP Code.
 *
 * Supports both displaying the EPP Code directly to a user or indicating
 * that the EPP Code will be emailed to the registrant.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 *
 */
function nask_GetEPPCode($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('RequestEPPCode', $postfields);

        if ($api->getFromResponse('eppcode')) {
            // If EPP Code is returned, return it for display to the end user
            return array(
                'eppcode' => $api->getFromResponse('eppcode'),
            );
        } else {
            // If EPP Code is not returned, it was sent by email, return success
            return array(
                'success' => 'success',
            );
        }

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Release a Domain.
 *
 * Used to initiate a transfer out such as an IPSTAG change for .UK
 * domain names.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_ReleaseDomain($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // transfer tag
    $transferTag = $params['transfertag'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'newtag' => $transferTag,
    );

    try {
        $api = new ApiClient();
        $api->call('ReleaseDomain', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Delete Domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_RequestDelete($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('DeleteDomain', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Register a Nameserver.
 *
 * Adds a child nameserver for the given domain name.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_RegisterNameserver($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];
    $ipAddress = $params['ipaddress'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
        'ip' => $ipAddress,
    );

    try {
        $api = new ApiClient();
        $api->call('RegisterNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Modify a Nameserver.
 *
 * Modifies the IP of a child nameserver.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_ModifyNameserver($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];
    $currentIpAddress = $params['currentipaddress'];
    $newIpAddress = $params['newipaddress'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
        'currentip' => $currentIpAddress,
        'newip' => $newIpAddress,
    );

    try {
        $api = new ApiClient();
        $api->call('ModifyNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Delete a Nameserver.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_DeleteNameserver($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // nameserver parameters
    $nameserver = $params['nameserver'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'nameserver' => $nameserver,
    );

    try {
        $api = new ApiClient();
        $api->call('DeleteNameserver', $postfields);

        return array(
            'success' => 'success',
        );

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Sync Domain Status & Expiration Date.
 *
 * Domain syncing is intended to ensure domain status and expiry date
 * changes made directly at the domain registrar are synced to WHMCS.
 * It is called periodically for a domain.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_Sync($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );

    // user defined configuration values
    $host = $params['Host'];
    $user = $params['Username'];
    $pass = $params['Password'];
    $ca = $params['CACert'];
    $cert = $params['Cert'];
    $key = $params['PrivateKey'];

    // domain parameters
    $domain = $params['sld'].'.'.$params['tld'];

    try {
        $client = new ApiClient($host, $user, $pass, $ca, $cert, $key);
        $data = $client->getDomainInfo($domain);

        if(isset($data['error']) && $data['error']){
            // we haf error hier!
            return [
                'error' => "NASK/Sync: Error [{$data['code']}] - {$data['message']} ",
            ];
        }

        if($data['clID'] !== $user) {
            //domain not ours ;(
            return [
                'transferredAway' => true,
            ];
        }

        if(!empty($data['status'])){
            if(!is_array($data['status'])){
                $data['status'] = [$data['status']];
            }
        } else {
            $data['status'] = [];
        }
        if(in_array('pendingDelete', $data['status'])){
            //domain is expired :/
            return [
                'active' => false,
                'expired' => true,
                'expirydate' => date('Y-m-d', strtotime($data['exDate'])),
            ];
        }

        return [
            'active' => true,
            'expirydate' => date('Y-m-d', strtotime($data['exDate'])),
        ];

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Incoming Domain Transfer Sync.
 *
 * Check status of incoming domain transfers and notify end-user upon
 * completion. This function is called daily for incoming domains.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_TransferSync($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
    );

    try {
        $api = new ApiClient();
        $api->call('CheckDomainTransfer', $postfields);

        if ($api->getFromResponse('transfercomplete')) {
            return array(
                'completed' => true,
                'expirydate' => $api->getFromResponse('expirydate'), // Format: YYYY-MM-DD
            );
        } elseif ($api->getFromResponse('transferfailed')) {
            return array(
                'failed' => true,
                'reason' => $api->getFromResponse('failurereason'), // Reason for the transfer failure if available
            );
        } else {
            // No status change, return empty array
            return array();
        }

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}

/**
 * Client Area Custom Button Array.
 *
 * Allows you to define additional actions your module supports.
 * In this example, we register a Push Domain action which triggers
 * the `nask_push` function when invoked.
 *
 * @return array
 */
function nask_ClientAreaCustomButtonArray()
{
    return array(
        'Push Domain' => 'push',
    );
}

/**
 * Client Area Allowed Functions.
 *
 * Only the functions defined within this function or the Client Area
 * Custom Button Array can be invoked by client level users.
 *
 * @return array
 */
function nask_ClientAreaAllowedFunctions()
{
    return array(
        'Push Domain' => 'push',
    );
}

/**
 * Example Custom Module Function: Push
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return array
 */
function nask_push($params)
{
    logModuleCall(
        'NASK',
        __FUNCTION__,
        $params,
        [],
        [],
        array(
            'username', // Mask username & password in request/response data
            'password',
        )
        );
    return [
        'error' => 'Not implemented YET'
    ];
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // domain parameters
    $sld = $params['sld'];
    $tld = $params['tld'];

    // Perform custom action here...

    return 'Not implemented';
}

/**
 * Client Area Output.
 *
 * This function renders output to the domain details interface within
 * the client area. The return should be the HTML to be output.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/domain-registrars/module-parameters/
 *
 * @return string HTML Output
 */
function nask_ClientArea($params)
{
    $output = '
        <div class="alert alert-info">
            Your custom HTML output goes here...
        </div>
    ';

    return $output;
}
