<?php
require_once 'config.php';

header('Content-Type: application/json');

class MediumImporter {
    private $pdo;
    private $username;

    public function __construct($dbConfig) {
        $this->username = '@onder7';
        try {
            $this->pdo = new PDO(
                "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
                $dbConfig['username'],
                $dbConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
    }

    public function importArticle($articleId) {
        try {
            // Yazı zaten var mı kontrol et
            if ($this->articleExists($articleId)) {
                return ['success' => false, 'message' => 'Bu yazı zaten içe aktarılmış.'];
            }

            // Medium'dan yazı detaylarını al
            $article = $this->fetchArticleFromMedium($articleId);
            if (!$article) {
                return ['success' => false, 'message' => 'Yazı Medium\'dan alınamadı.'];
            }

            $this->pdo->beginTransaction();

            // Yazıyı veritabanına kaydet
            $postId = $this->savePost($article);

            // Etiketleri kaydet
            if (!empty($article['categories'])) {
                foreach ($article['categories'] as $category) {
                    $tagId = $this->getOrCreateTag($category);
                    $this->addPostTag($postId, $tagId);
                }
            }

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Yazı başarıyla içe aktarıldı.'];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage()];
        }
    }

    private function articleExists($articleId) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM posts WHERE medium_id = ?");
        $stmt->execute([$articleId]);
        return $stmt->fetchColumn() > 0;
    }

    private function fetchArticleFromMedium($articleId) {
        $feedUrl = "https://medium.com/feed/@{$this->username}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $feedUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            throw new Exception("RSS feed alınamadı");
        }

        $xml = simplexml_load_string($response);
        if (!$xml) {
            throw new Exception("RSS verisi işlenemedi");
        }

        foreach ($xml->channel->item as $item) {
            $link = (string)$item->link;
            if (strpos($link, $articleId) !== false) {
                return [
                    'title' => (string)$item->title,
                    'content' => (string)$item->children('content', true)->encoded,
                    'description' => (string)$item->description,
                    'pubDate' => (string)$item->pubDate,
                    'link' => $link,
                    'categories' => isset($item->category) ? iterator_to_array($item->category) : [],
                    'medium_id' => $articleId
                ];
            }
        }

        return null;
    }

    private function savePost($article) {
        $stmt = $this->pdo->prepare("
            INSERT INTO posts (
                title, slug, content, excerpt, author_id,
                status, medium_id, published_at, created_at, updated_at
            ) VALUES (
                :title, :slug, :content, :excerpt, :author_id,
                'published', :medium_id, :published_at, NOW(), NOW()
            )
        ");

        $slug = $this->createSlug($article['title']);
        $excerpt = substr(strip_tags($article['description']), 0, 255);

        $stmt->execute([
            'title' => $article['title'],
            'slug' => $slug,
            'content' => $article['content'],
            'excerpt' => $excerpt,
            'author_id' => 1, // Varsayılan yazar ID
            'medium_id' => $article['medium_id'],
            'published_at' => date('Y-m-d H:i:s', strtotime($article['pubDate']))
        ]);

        return $this->pdo->lastInsertId();
    }

    private function createSlug($title) {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'g', 'u', 's', 'o', 'c'], $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    private function getOrCreateTag($tagName) {
        $stmt = $this->pdo->prepare("SELECT id FROM tags WHERE name = ?");
        $stmt->execute([$tagName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['id'];
        }

        $stmt = $this->pdo->prepare("INSERT INTO tags (name, slug) VALUES (?, ?)");
        $stmt->execute([$tagName, $this->createSlug($tagName)]);
        
        return $this->pdo->lastInsertId();
    }

    private function addPostTag($postId, $tagId) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO post_tag (post_id, tag_id) VALUES (?, ?)");
        $stmt->execute([$postId, $tagId]);
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['articleId'])) {
        throw new Exception('Makale ID\'si gerekli');
    }

    $importer = new MediumImporter($GLOBALS['dbConfig']);
    echo json_encode($importer->importArticle($input['articleId']));

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
