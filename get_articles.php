<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

class MediumArticleFetcher {
    private $username;
    private $articles = [];
    private $debug = true;
    
    public function __construct($username) {
        $this->username = ltrim($username, '@');
    }

    private function log($message) {
        if ($this->debug) {
            error_log("[DEBUG] " . $message);
        }
    }

    public function fetchArticles() {
        $this->log("Başlıyor...");
        
        // Web sayfasından veri çek
        $this->scrapeFromWeb();
        
        // RSS'den de al
        $this->fetchFromRSS();

        // Tarihe göre sırala
        usort($this->articles, function($a, $b) {
            return strtotime($b['pubDate']) - strtotime($a['pubDate']);
        });

        // Tekrarları temizle
        $this->articles = array_values($this->removeDuplicates());

        $this->log("Toplam " . count($this->articles) . " yazı bulundu.");

        return [
            'success' => true,
            'articles' => $this->articles,
            'total' => count($this->articles)
        ];
    }

    private function scrapeFromWeb() {
        $url = "https://medium.com/@{$this->username}/latest";
        $this->log("Web sayfası çekiliyor: " . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/96.0.4664.110 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ]
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log("HTTP Kodu: " . $httpCode);

        // Debug için HTML'i kaydet
        file_put_contents('debug_page.html', $html);
        $this->log("HTML kaydedildi: debug_page.html");

        // JavaScript state verisini bul
        if (preg_match('/"__APOLLO_STATE__":\s*({.+?})\s*,\s*"/', $html, $matches)) {
            $jsonData = $matches[1];
            $data = json_decode($jsonData, true);
            $this->log("Apollo state verisi bulundu");

            if ($data) {
                foreach ($data as $key => $value) {
                    if (isset($value['__typename']) && $value['__typename'] === 'Post') {
                        $this->log("Yazı bulundu: " . ($value['title'] ?? 'Başlıksız'));
                        $this->articles[] = [
                            'id' => $value['id'] ?? '',
                            'title' => $value['title'] ?? '',
                            'link' => "https://medium.com/@{$this->username}/" . ($value['uniqueSlug'] ?? ''),
                            'pubDate' => isset($value['firstPublishedAt']) ? 
                                       date('r', $value['firstPublishedAt'] / 1000) : 
                                       date('r'),
                            'excerpt' => $value['previewContent']['subtitle'] ?? '',
                            'categories' => []
                        ];
                    }
                }
            }
        }

        // Article elementlerini de kontrol et
        preg_match_all('/<article[^>]*>(.*?)<\/article>/s', $html, $matches);
        
        if (!empty($matches[1])) {
            $this->log("HTML'den " . count($matches[1]) . " article elementi bulundu");
            foreach ($matches[1] as $articleHtml) {
                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $articleHtml, $titleMatch) &&
                    preg_match('/href="([^"]*)"/', $articleHtml, $linkMatch)) {
                    
                    $link = $linkMatch[1];
                    if (strpos($link, 'http') !== 0) {
                        $link = 'https://medium.com' . $link;
                    }
                    
                    $this->articles[] = [
                        'id' => basename(parse_url($link, PHP_URL_PATH)),
                        'title' => strip_tags($titleMatch[1]),
                        'link' => $link,
                        'pubDate' => date('r'), // Tam tarih HTML'de gizli olabilir
                        'excerpt' => $this->extractExcerpt($articleHtml),
                        'categories' => []
                    ];
                }
            }
        }
    }

    private function extractExcerpt($html) {
        if (preg_match('/<p[^>]*>(.*?)<\/p>/s', $html, $match)) {
            return strip_tags($match[1]);
        }
        return '';
    }

    private function fetchFromRSS() {
        $feedUrl = "https://medium.com/feed/@{$this->username}";
        $this->log("RSS feed çekiliyor: " . $feedUrl);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $feedUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $xml = @simplexml_load_string($response);
            if ($xml) {
                $this->log("RSS feed başarıyla parse edildi");
                foreach ($xml->channel->item as $item) {
                    $link = (string)$item->link;
                    $content = isset($item->children('content', true)->encoded) ? 
                              (string)$item->children('content', true)->encoded : 
                              (string)$item->description;

                    $categories = [];
                    if (isset($item->category)) {
                        foreach ($item->category as $category) {
                            $categories[] = (string)$category;
                        }
                    }

                    $this->articles[] = [
                        'id' => basename(parse_url($link, PHP_URL_PATH)),
                        'title' => html_entity_decode((string)$item->title, ENT_QUOTES, 'UTF-8'),
                        'link' => $link,
                        'pubDate' => (string)$item->pubDate,
                        'excerpt' => strip_tags($content),
                        'categories' => $categories
                    ];
                }
            }
        }
    }

    private function removeDuplicates() {
        $seen = [];
        return array_filter($this->articles, function($article) use (&$seen) {
            $key = $article['id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        });
    }
}

try {
    $fetcher = new MediumArticleFetcher('@onder7');
    $result = $fetcher->fetchArticles();
    
    // Debug bilgisi ekle
    $result['debug'] = [
        'time' => date('Y-m-d H:i:s'),
        'memory_usage' => memory_get_usage(true),
        'php_version' => PHP_VERSION
    ];
    
    echo json_encode($result, 
        JSON_PRETTY_PRINT | 
        JSON_UNESCAPED_UNICODE | 
        JSON_UNESCAPED_SLASHES
    );
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'articles' => [],
        'total' => 0
    ]);
}
?>
