<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medium Yazılarım</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        .card-hover:hover {
            transform: translateY(-5px);
            transition: transform 0.2s ease-in-out;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .content-preview {
            max-height: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 1rem;
        }
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }
        #loadingSpinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            display: none;
        }
        .loading #loadingSpinner {
            display: block;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fab fa-medium me-2"></i>
                Medium Yazılarım
            </a>
            <div id="articleCount" class="navbar-text text-white">
                Yazılar yükleniyor...
            </div>
        </div>
    </nav>

    <div class="container">
        <div id="articles" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
            <!-- Yazılar buraya gelecek -->
        </div>
        
        <div id="loadingSpinner" class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Yükleniyor...</span>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
    document.body.classList.add('loading');


    fetch('get_articles.php')
    .then(response => response.json())
    .then(data => {
        document.body.classList.remove('loading');
        
        if (!data.success) {
            throw new Error(data.error || 'Bilinmeyen bir hata oluştu');
        }
        
        if (data.total > 0) {
            document.getElementById('articleCount').textContent = 
                `Toplam ${data.total} yazı`;
            
            const articlesHTML = data.articles.map(article => {
                const date = new Date(article.pubDate).toLocaleDateString('tr-TR');
                const tags = article.categories.map(tag => 
                    `<span class="badge bg-secondary me-1 mb-1">${tag}</span>`
                ).join('');

                return `
                    <div class="col">
                        <div class="card h-100 card-hover">
                            <div class="card-body">
                                <h5 class="card-title">${article.title}</h5>
                                <p class="card-text text-muted">
                                    <small>
                                        <i class="far fa-calendar-alt me-2"></i>${date}
                                    </small>
                                </p>
                                <p class="card-text content-preview">${article.excerpt}</p>
                                <div class="mb-3">${tags}</div>
                                <div class="d-flex gap-2">
                                    <a href="${article.link}" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-external-link-alt me-2"></i>Yazıyı Oku
                                    </a>
                                    <button class="btn btn-success btn-sm" onclick="importArticle('${article.id}')">
                                        <i class="fas fa-file-import me-2"></i>İçe Aktar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            document.getElementById('articles').innerHTML = articlesHTML;
        } else {
            document.getElementById('articles').innerHTML = `
                <div class="col-12">
                    <div class="alert alert-warning">
                        Hiç yazı bulunamadı.
                    </div>
                </div>
            `;
        }
    })
    .catch(error => {
        document.body.classList.remove('loading');
        console.error('Error:', error);
        document.getElementById('articles').innerHTML = `
            <div class="col-12">
                <div class="alert alert-danger">
                    Yazılar yüklenirken bir hata oluştu:<br>
                    ${error.message}
                </div>
            </div>
        `;
        document.getElementById('articleCount').textContent = 'Hata oluştu';
    });
    function importArticle(articleId) {
        if (confirm('Bu yazıyı blogunuza aktarmak istediğinize emin misiniz?')) {
            document.body.classList.add('loading');
            
            fetch('import.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    articleId: articleId
                })
            })
            .then(response => response.json())
            .then(data => {
                document.body.classList.remove('loading');
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                document.body.classList.remove('loading');
                console.error('Error:', error);
                alert('Yazı aktarılırken bir hata oluştu.');
            });
        }
    }
    </script>
</body>
</html>
