<?php
require 'vendor/autoload.php';
require "config.php";

use GuzzleHttp\Client;

class Scrap {
    /**
     * HTTP Client
     * @var GuzzleHttp\Client
     */
    private $client;

    /**
     * HTTP Request timeout
     * @var int
     */
    private $timeout = 5;

    private $filename = "./diskfacil.csv";

    /**
     * Sigla do Estado 
     */
    private $estado;

    /**
     * Nome da cidade
     */
    private $cidade;

    private $length;

    /**
     * Data
     * @var array
     */
    private $data = [];

    /**
     * CSV File pointer
     */
    private $csv;
    
    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }

    public function start() {
        $this->client = new Client([
            'base_uri' => 'https://www.diskfacil.com.br/',
            'timeout'  => $this->timeout,
        ]);

        // format
        $this->estado = strtolower(ESTADO);
        $this->cidade = strtolower(CIDADE);

        $this->createCsv();

        // Get first page
        $this->first();
    }

    private function first($auto_loop = true) {
        $response = $this->client->request('GET', $this->estado . "/" . $this->cidade);

        if ($response->getStatusCode() == 200) {
            $html = $response->getBody()->getContents();

            // Search for number of pagination
            $dom = new DOMDocument();
            $dom->loadHTML($html, LIBXML_NOERROR);

            $xpath = new DOMXPath($dom);

            $results = $xpath->query("//ul[@class='pagination']/li");

            if ($results->length > 0) {
                foreach ($results as $li) {
                    $link = $li->getElementsByTagName("a");
                    if ($link->length == 1) {
                        $url = $link->item(0)->getAttribute("href");                        
                        $url_parts = parse_url($url);

                        preg_match('/page\=(\d+)/', $url_parts["query"], $matches);

                        $page = (int)($matches[1]);

                        if ($page > $this->length) {
                            $this->length = $page;
                        }
                    }
                }
            }

            $this->download($html);

            if ($auto_loop) {
                if ($this->length) {
                    sleep(5);
                    
                    // Loop on pages
                    for ($i = 2; $i <= $this->length; $i++) {
                        $responsePage = $this->client->request('GET', $this->estado . "/" . $this->cidade . "?page=" . $i);
                        if ($responsePage->getStatusCode() == 200) {
                            $html = $responsePage->getBody()->getContents();
                            $this->download($html);

                            sleep(5);
                        }
                    }
                }
            }
        }        
    }

    private function download($html) {
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);

        $xpath = new DOMXPath($dom);       

        $results = $xpath->query("//div[@itemscope]");

        if ($results->length) {
            // Get CSRF
            $resultCsrf = $xpath->query("//meta[@name='csrf-token']");

            if (!$resultCsrf->length) return;
            $csrf = $resultCsrf->item(0)->getAttribute("content");

            foreach ($results as $entry) {
                $business = [];

                // Get business name
                $resultEntry = $xpath->query("div[@class='anunciante-nome']", $entry);

                if ($resultEntry->length) {
                    $business["nome"] = $this->clearString($resultEntry->item(0)->textContent);
                }

                // Get business address 
                $resultAddress = $xpath->query("div/div/div/div/p[@itemprop='address']", $entry);
                //$resultAddress = $xpath->query("div/div/div/div/p/span[@itemprop='street-address']", $entry);
                if ($resultAddress->length) {
                    $addressData = explode(PHP_EOL, trim($resultAddress->item(0)->textContent));

                    $addressData = array_filter($addressData, function ($element) {
                        $elementFormatted = trim(str_replace(" ", "", strip_tags($element)));
                        if ($elementFormatted) {
                            return $elementFormatted;
                        }
                        return null;
                    });
                    
                    $addressData = array_map(function ($element) {
                        $elementFormatted = trim(strip_tags($element));
                        if ($elementFormatted) {
                            return $elementFormatted;
                        }
                    }, $addressData);

                    ksort($addressData);

                    $business["endereco"] = join(" ", $addressData);
                }

                // Get telephone
                $resultTelephone = $xpath->query("div/div/div/div/a[@ng-show]", $entry);
                if ($resultTelephone->length) {
                    $a = $resultTelephone->item(0);

                    preg_match('/\!controle\.telefone\_(\d+)/', $a->getAttribute("ng-show"), $matches);

                    $idTel = $matches[1];
                    $business["telefone"] = $this->getTelephone($idTel, $csrf);
                }

                fputcsv($this->csv, $business, ";");
            }
        }
    }

    private function getTelephone($id, $csrf) {
        $response = $this->client->request('GET', "api/logradouro/".$id."/telefone",
            [
                'headers' => [
                    'X-CSRF-TOKEN' => $csrf,
                ]
            ]
        );
        if ($response->getStatusCode() == 200) {
            return str_replace('"', "", $response->getBody()->getContents());
        }
    }

    private function clearString($string) {
        return trim(strip_tags(str_replace(["&nbsp;", "  ", "\r\n"], ["", "", ""], $string)));
    }

    private function createCsv() {
        $this->csv = fopen($this->filename, "w+");
    }
}