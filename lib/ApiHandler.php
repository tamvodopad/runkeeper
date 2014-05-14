<?php

namespace opus\runkeeper;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * RunKeeper main class
 *
 * @author Ivo Kund <ivo@opus.ee>
 */
class ApiHandler
{
    private $client_id;
    private $client_secret;
    private $auth_url;
    private $access_token_url;
    private $redirect_uri;
    private $api_base_url;
    private $api_conf_file;
    public $api_conf;
    public $api_created = false;
    public $api_last_error = null;
    public $access_token = null;
    public $token_type = 'Bearer';
    public $requestRedirectUrl = null;
    public $api_request_log = null;

    /**
     * Build a new instance of RunKeeper
     *
     * @param string $api_conf_file Path to the configuration file
     */
    public function __construct($api_conf_file)
    {
        $this->api_conf_file = $api_conf_file;

        try {
            if (!file_exists($api_conf_file) || !is_file($api_conf_file) || !is_readable($api_conf_file)) {
                $this->api_last_error = "Unable to find/read the YAML api_conf_file : $api_conf_file";
                $this->api_created = false;
            } else {
                $values = Yaml::parse($api_conf_file);
                $this->api_conf = json_decode(json_encode($values));
                $this->client_id = $this->api_conf->App->client_id;
                $this->client_secret = $this->api_conf->App->client_secret;
                $this->auth_url = $this->api_conf->App->auth_url;
                $this->access_token_url = $this->api_conf->App->access_token_url;
                $this->redirect_uri = $this->api_conf->App->redirect_uri;
                $this->api_base_url = $this->api_conf->App->api_base_url;
                $this->api_created = true;
            }
        } catch (ParseException $e) {
            $this->api_last_error = "Unable to parse the YAML string: " . $e->getMessage();
            $this->api_created = false;
        }
    }

    /**
     * Get the URL for the login button
     *
     * @return string
     */
    public function connectRunkeeperButtonUrl()
    {
        $url = $this->auth_url . '?response_type=code&client_id=' . $this->client_id . '&redirect_uri=' . urlencode(
                $this->redirect_uri
            );

        return ($url);
    }

    /**
     * Get the token from the authorization code
     *
     * @param string $authorization_code
     * @param string $redirect_uri
     *
     * @return string
     */
    public function getRunkeeperToken($authorization_code, $redirect_uri = '')
    {
        $params = http_build_query(
            [
                'grant_type' => 'authorization_code',
                'code' => $authorization_code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => ($redirect_uri == '' ? $this->redirect_uri : $redirect_uri)
            ]
        );
        $options = [
            CURLOPT_URL => $this->access_token_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true
        ];
        $curl = curl_init();
        curl_setopt(
            $curl,
            CURLOPT_SSL_VERIFYPEER,
            false
        ); /* Added to avoid "error :SSL certificate problem, verify that the CA cert is OK" */
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        curl_close($curl);
        $decodeResponse = json_decode($response);
        if ($decodeResponse == null) {
            $this->api_last_error = "getRunkeeperToken: bad response";

            return (false);
        } elseif (!isset($decodeResponse->error)) {
            if (isset($decodeResponse->access_token)) {
                $this->access_token = $decodeResponse->access_token;
            }
            if (isset($decodeResponse->token_type)) {
                $this->token_type = $decodeResponse->token_type;
            }

            return (true);
        } elseif ($decodeResponse->error == 'invalid_grant') {
            header(
                'Location: ' . $this->auth_url . '?response_type=code&client_id=' . $this->client_id . '&redirect_uri=' . urlencode(
                    $this->redirect_uri
                ),
                true,
                302
            );
            exit();
        } else {
            $this->api_last_error = "getRunkeeperToken: " . $decodeResponse->error;

            return (false);
        }
    }

    /**
     * Set the token to use
     *
     * @param string $access_token
     */
    public function setRunkeeperToken($access_token)
    {
        $this->access_token = $access_token;
    }

    /**
     * Do a request on the API
     *
     * @param string $name
     * @param string $type
     * @param array $fields
     * @param string $url
     * @param array $optParams
     *
     * @return array
     */
    public function doRunkeeperRequest($name, $type, $fields = null, $url = null, $optParams = null)
    {
        $this->requestRedirectUrl = null;
        $orig = microtime(true);
        if (empty($name) || !isset($this->api_conf->Interfaces->$name)) {
            $this->api_last_error = "doRunkeeperRequest: wrong or missing Interface name : " . $name;

            return (false);
        } elseif (!$type || !isset($this->api_conf->Interfaces->$name->$type)) {
            $this->api_last_error = "doRunkeeperRequest: not supported or missing type (Read, Update, Create or Delete)";

            return (false);
        } else {
            $options = [];
            $params = ($optParams == null ? '' : '?' . http_build_query($optParams));
            switch ($this->api_conf->Interfaces->$name->$type->Method) {
                case 'GET':
                    $options = [
                        CURLOPT_HTTPHEADER => [
                            'Authorization: ' . $this->token_type . ' ' . $this->access_token,
                            'Accept: ' . $this->api_conf->Interfaces->$name->Media_Type
                        ],
                        CURLOPT_URL => ($url == null ? $this->api_base_url . $this->api_conf->Interfaces->$name->$type->Url : (strstr(
                                $url,
                                'http://'
                            ) || strstr($url, 'https://') ? $url : $this->api_base_url . $url)) . $params,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLINFO_HEADER_OUT => true,
                    ];
                    break;
                case 'POST':
                    $jsonFields = json_encode($fields);
                    $options = [
                        CURLOPT_HTTPHEADER => [
                            'Authorization: ' . $this->token_type . ' ' . $this->access_token,
                            'Content-Type: ' . $this->api_conf->Interfaces->$name->Media_Type,
                            'Content-Length: ' . strlen($jsonFields)
                        ],
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_URL => ($url == null ? $this->api_base_url . $this->api_conf->Interfaces->$name->$type->Url : (strstr(
                                $url,
                                'http://'
                            ) || strstr($url, 'https://') ? $url : $this->api_base_url . $url)) . $params,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLINFO_HEADER_OUT => true,
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_POSTFIELDS => $jsonFields
                    ];
                    break;
                case 'PUT':
                    $jsonFields = json_encode($fields);
                    $options = [
                        CURLOPT_HTTPHEADER => [
                            'Authorization: ' . $this->token_type . ' ' . $this->access_token,
                            'Content-Type: ' . $this->api_conf->Interfaces->$name->Media_Type,
                            'Content-Length: ' . strlen($jsonFields)
                        ],
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_URL => ($url == null ? $this->api_base_url . $this->api_conf->Interfaces->$name->$type->Url : (strstr(
                                $url,
                                'http://'
                            ) || strstr($url, 'https://') ? $url : $this->api_base_url . $url)) . $params,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLINFO_HEADER_OUT => true,
                        CURLOPT_CUSTOMREQUEST => 'PUT',
                        CURLOPT_POSTFIELDS => $jsonFields
                    ];
                    break;
                case 'DELETE':
                    $options = array(
                        CURLOPT_HTTPHEADER => [
                            'Authorization: ' . $this->token_type . ' ' . $this->access_token,
                            'Content-Type: ' . $this->api_conf->Interfaces->$name->Media_Type,
                            'Content-Length: 0'
                        ],
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_URL => ($url == null ? $this->api_base_url . $this->api_conf->Interfaces->$name->$type->Url : (strstr(
                                $url,
                                'http://'
                            ) || strstr($url, 'https://') ? $url : $this->api_base_url . $url)) . $params,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLINFO_HEADER_OUT => true,
                        CURLOPT_CUSTOMREQUEST => 'PUT'
                    );

                    break;
            }
            $curl = curl_init();
            curl_setopt(
                $curl,
                CURLOPT_SSL_VERIFYPEER,
                false
            ); /* Added to avoid "error :SSL certificate problem, verify that the CA cert is OK" */
            curl_setopt_array($curl, $options);
            curl_setopt(
                $curl,
                CURLOPT_HEADERFUNCTION,
                [&$this, 'parseHeader']
            ); /* add callback header function to process response headers */
            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($this->requestRedirectUrl != null) {
                /* After creating new activity/measurement : get a Location header with url to retrieve created activity/measurement */
                $parentName = (!property_exists(
                    $this->api_conf->Interfaces->$name,
                    'Parent'
                ) ? $this->api_conf->Interfaces->$name->Name : $this->api_conf->Interfaces->$name->Parent);
                $this->api_request_log[] = [
                    'name' => $name,
                    'type' => $type,
                    'result' => 'redirect',
                    'time' => microtime(true) - $orig
                ];

                return $this->doRunkeeperRequest($parentName, 'Read', $fields, $this->requestRedirectUrl, $optParams);
            } else {
                if ($responseCode === 200) {
                    $response = htmlentities($response, ENT_NOQUOTES);
                    $decodeResponse = json_decode($response);
                    $this->api_request_log[] = [
                        'name' => $name,
                        'type' => $type,
                        'result' => 200,
                        'responseCode' => $responseCode,
                        'time' => microtime(true) - $orig
                    ];

                    return ($decodeResponse);
                } elseif (in_array($responseCode, ['201', '204', '301', '304'])) {
                    $this->api_request_log[] = [
                        'name' => $name,
                        'type' => $type,
                        'result' => $responseCode,
                        'responseCode' => $responseCode,
                        'time' => microtime(true) - $orig
                    ];

                    return true;
                } else {
                    $this->api_last_error = "doRunkeeperRequest: request error => 'name' : " . $name . ", 'type' : " . $type . ", 'result' : " . $responseCode . ", '" . $name . "' => " . $url;
                    $this->api_request_log[] = [
                        'name' => $name,
                        'type' => $type,
                        'result' => 'error : ' . $responseCode,
                        'responseCode' => $responseCode,
                        'time' => microtime(true) - $orig
                    ];

                    return false;
                }
            }
        }
    }

    /**
     * Parse an header
     *
     * @param resource $curl
     * @param string $header
     *
     * @return integer
     */
    private function parseHeader($curl, $header)
    {
        if (strstr($header, 'Location: ')) {
            $this->requestRedirectUrl = substr($header, 10, strlen($header) - 12);
        }

        return strlen($header);
    }
}

