<?php

/**
 * Экстремально простой класс для работы с amoCRM
 *
 * @docs      https://github.com/phpony/amo
 * @license   MIT
 */
class amoSend 
{
    // Ваши настройки
    private $subdomain = 'example';
    private $redirect = 'https://example.com/amo';
    private $client_id = '00000000-aaaa-bbbb-cccc-dddddddddddd';
    private $client_secret = 'abcdefABCDEF0123456789abcdefABCDEF0123456789';
    private $pipelineId = 0;
    private $sourceId = 'website';    

    // Служебные переменные
    private $active = false;   
    private $tokens = array();
    private $dir = '';
    private $lastError = ''; 

    public function __construct($tmpDir = '') {
        $this->dir = !empty($tmpDir) && is_dir($tmpDir) ? $tmpDir : getcwd();    
        if(file_exists($this->dir."/amo_tokens.json")) {
            $this->tokens = json_decode(file_get_contents($this->dir."/amo_tokens.json"), true);
            if(!empty($this->tokens['access_token']) && !empty($this->tokens['refresh_token'])) {
                $this->active = true;
                if(time()-filemtime($this->dir."/amo_tokens.json") > $this->tokens['expires_in']) {
                    $this->refreshToken();
                }                
            }
        }
    }

    public function getError() {
        if(!empty($this->lastError)) {
            return $this->lastError;
        }
        return false;
    }

    private function curlRequest($link, $data, $bearer = false) {
        $curl = curl_init(); 
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'amoCRM-oAuth-client/1.0',
            CURLOPT_HTTPHEADER => array('Content-Type:application/json'),
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        curl_setopt($curl,CURLOPT_URL, 'https://' . $this->subdomain . '.amocrm.ru'.$link);
        curl_setopt($curl,CURLOPT_POSTFIELDS, json_encode($data));        
        if ($bearer) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $this->tokens['access_token']));
        }
        $out = curl_exec($curl); 
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return array($code, $out);
    }
    
    public function init($authCode) {
        $data = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'redirect_uri' => $this->redirect
        );
        list($code, $out) = $this->curlRequest('/oauth2/access_token', $data);
        if ($code < 200 || $code > 204) {
            $this->lastError = "Ошибка от amoCRM: {$code} [$out]";
            return false;
        } 
        file_put_contents($this->dir."/amo_tokens.json", $out);
        if($this->tokens = json_decode($out, true)) {
            $this->active = true;
            return true;
        } else {
            $this->lastError = "Некорректный пакет от amoCRM - это не json: [$out]";
            return false;
        }
    }

    public function refreshToken() {    
        if(!$this->active) {
            $this->lastError = 'Интеграция не настроена. Сначала запустите init() с временным 20-минутным кодом из настроек интеграции.';
            return false;
        }
        $data = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->tokens['refresh_token'],
            'redirect_uri' => $this->redirect
        );
        list($code, $out) = $this->curlRequest('/oauth2/access_token', $data);
        if ($code < 200 || $code > 204) {
            $this->lastError = "Ошибка от amoCRM: {$code} [$out]";
            return false;
        } 
        file_put_contents($this->dir."/amo_tokens.json", $out);
        if($this->tokens = json_decode($out, true)) {
            $this->active = true;
        } else {
            $this->lastError = "Некорректный пакет от amoCRM - это не json. Ошибка на стороне сервера?";
            return false;
        }
    }

    public function sendLead($formData = array(), $formId = 'form', $sourceName = 'Заявка с сайта') {
        if(!$this->active) {
            $this->lastError = 'Интеграция не настроена. Сначала запустите init() с временным 20-минутным кодом из настроек интеграции.';
            return false;
        }
        $lead = array(
            "name" => !empty($formData['type']) ? $formData['type'] : 'Заявка с сайта',
            "custom_fields_values" => array(),            
            "_embedded" => array(               
                "tags" => array(array("name" => "заявка_с_сайта"))          
            )
        );
        if(!empty($formData['lead_fields'])) {
            foreach($formData['lead_fields'] as $k=>$v) {
                if(is_numeric($k) && !empty($v)) {
                    $lead["custom_fields_values"][] = array(
                        "field_id" => $k,
                        "values" => array(array(
                            "value" => $v
                        ))     
                    );
                }
            }
        }       
        $contact = array(
            "name" => $formData['name'],
            "custom_fields_values" => array()
        );        
        if(!empty($formData['phone'])) {
            $contact["custom_fields_values"][] = array(
                "field_code" => "PHONE",
                "values" => array(array(
                    "value" => $formData['phone']
                ))
            );
        }        
        if(!empty($formData['email'])) {
            $contact["custom_fields_values"][] = array(
                "field_code" => "EMAIL",
                "values" => array(array(
                    "value" => $formData['email']
                ))
            );
        }
        if(!empty($formData['contact_fields'])) {
            foreach($formData['contact_fields'] as $k=>$v) {
                if(is_numeric($k) && !empty($v)) {
                    $contact["custom_fields_values"][] = array(
                        "field_id" => $k,
                        "values" => array(array(
                            "value" => $v
                        ))     
                    );
                }
            }
        }         
        $data = array(array(
            "request_id" => uniqid(),
            "source_name" => $sourceName,
            "source_uid" => !empty($this->sourceId) ? $this->sourceId : "website",
            "pipeline_id" => !empty($this->pipelineId) ? $this->pipelineId : null,
            "created_at" => time(),
            "_embedded" => array(
                "leads" => array($lead),
                "contacts" => array($contact)
            ),
            "metadata" => array(
                "ip" => !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
                "form_sent_at" => time(),
                "form_id" => $formId,
                "form_name" => !empty($formData['type']) ? $formData['type'] : 'Заявка с сайта',                
                "form_page" => !empty($_SERVER['HTTP_HOST']) ? "https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}" : $redirect
            )
        ));
        if(!empty($_SERVER['HTTP_REFERER'])) {
            $data["metadata"]['referer'] = $_SERVER['HTTP_REFERER']; 
        }
        list($code, $out) = $this->curlRequest('/api/v4/leads/unsorted/forms', $data, true);
        if ($code < 200 || $code > 204) {
            $this->lastError = "Ошибка от amoCRM: {$code} [$out]";
            return false;
        } 
        return true;
    }
}

if(!empty($argv[1])) {
    switch($argv[1]) {
        case 'test':
            $amo = new amoSend(!empty($argv[2])?$argv[2]:'');
            if($amo->sendLead(array(
                'type' => 'Тестовая заявка с сайта',
                'name' => 'Иванов Иван Иванович',
                'phone' => '+78888888888',        
            ), "test_form", "Заявка с сайта example.com")) {
                echo "SUCCESS".PHP_EOL;
            } else {
                echo "FAIL: ".$amo->getError().PHP_EOL;
            }
            break;
        case 'refresh':
            $amo = new amoSend(!empty($argv[2])?$argv[2]:'');
            if($err = $amo->getError()) {
                echo "FAIL: {$err}".PHP_EOL;
            } else {
                echo "SUCCESS".PHP_EOL;
            }
            break;
        default:
            $amo = new amoSend(!empty($argv[2])?$argv[2]:'');   
            if($amo->init($argv[1])) {
                echo "SUCCESS".PHP_EOL;
            } else {
                echo "FAIL: ".$amo->getError().PHP_EOL;
            }
            break;            
    }
}
